#include <stdint.h>
#include <time.h>

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <esp_sleep.h>

#include <Wire.h>
#include <Adafruit_BME280.h>

#include <secrets.h>

#include "ca_certs.h"
#include "rtc_storage.h"
#include "transmission_json.h"

#define DEVICE_ID "sensor-001"

// ESP32 defaults - change to match the wiring.
#define BME280_SDA_PIN 21
#define BME280_SCL_PIN 22

// Time for the die to shed the heat begin() puts into it. See bme280Begin().
#define BME280_SETTLE_MS 500

// Full endpoint, e.g. "https://weather.example.com/api/v1/measurement".
#define API_URL "https://weather.rajtik.com/api/v1/measurement"

static const uint64_t MEASURE_INTERVAL_US = 10ULL * 60ULL * 1000000ULL;  // 10 min
static const uint64_t MIN_SLEEP_US = 10ULL * 1000000ULL;                 // safety floor

static const uint32_t WIFI_FAST_TIMEOUT_MS = 5000;   // known AP, no scan
static const uint32_t WIFI_SCAN_TIMEOUT_MS = 15000;  // full scan fallback
static const uint32_t NTP_TIMEOUT_MS = 10000;

// POSIX TZ for Czech Republic: UTC+1, DST from last Sunday in March
// to last Sunday in October at 03:00. Used only for display.
static const char* TZ_PRAGUE = "CET-1CEST,M3.5.0,M10.5.0/3";

// Sanity threshold: any epoch below this means the clock is not set.
static const uint32_t EPOCH_VALID_MIN = 1700000000UL;

// Cached AP details, so the next wakeup can skip the channel scan.
// The radio is the biggest consumer here, so every second of scanning costs.
RTC_DATA_ATTR static uint8_t rtcApBssid[6];
RTC_DATA_ATTR static uint8_t rtcApChannel;
RTC_DATA_ATTR static bool rtcApValid;

// ---------------------------------------------------------------- sensors

static Adafruit_BME280 bme;

static bool bme280Begin() {
  Wire.begin(BME280_SDA_PIN, BME280_SCL_PIN);

  // Boards ship with either address depending on how SDO is strapped.
  if (!bme.begin(0x76, &Wire) && !bme.begin(0x77, &Wire)) {
    Serial.println("BME280 not found");
    return false;
  }

  // Forced mode: the sensor takes one measurement on demand and goes back
  // to sleep, instead of cycling on its own while the ESP32 is in deep
  // sleep. Oversampling and filtering stay off - both average across
  // consecutive samples, and there is one sample every 10 minutes.
  bme.setSampling(Adafruit_BME280::MODE_FORCED,
                  Adafruit_BME280::SAMPLING_X1,  // temperature
                  Adafruit_BME280::SAMPLING_X1,  // pressure
                  Adafruit_BME280::SAMPLING_X1,  // humidity
                  Adafruit_BME280::FILTER_OFF);

  // begin() runs the sensor in normal mode at 16x oversampling for over
  // 100 ms before this switches it to forced, and that warms the die by
  // about 0.1 degC. Measured on this board, the reading is back within
  // 0.02 degC of ambient after ~350 ms and within 0.01 degC after ~700 ms.
  // Every wakeup pays this once - 500 ms of an ~80 mA CPU is 0.011 mAh,
  // against a WiFi window that already runs for seconds.
  delay(BME280_SETTLE_MS);

  // The data registers hold the previous conversion until a new one
  // finishes, and the reset inside begin() leaves no previous conversion -
  // the first forced read would return power-on defaults, not a
  // measurement. This throwaway conversion fills them with a real one.
  bme.takeForcedMeasurement();

  return true;
}

static bool readBme280(bme280_reading_t& out) {
  if (!bme.takeForcedMeasurement()) {
    Serial.println("BME280 measurement failed");
    return false;
  }

  float t = bme.readTemperature();  // degC
  float h = bme.readHumidity();     // %
  float p = bme.readPressure();     // Pa, already the unit the API takes

  if (isnan(t) || isnan(h) || isnan(p)) {
    Serial.println("BME280 returned NaN");
    return false;
  }

  long temperature = lroundf(t * 100.0f);
  long humidity = lroundf(h * 100.0f);
  long pressure = lroundf(p);

  // The server validates every entry and rejects the whole batch when one
  // value is out of range. A single bad reading would then block every
  // buffered reading behind it forever, so it gets dropped here instead.
  if (temperature < BME280_TEMP_MIN || temperature > BME280_TEMP_MAX ||
      humidity < BME280_HUMIDITY_MIN || humidity > BME280_HUMIDITY_MAX ||
      pressure < BME280_PRESSURE_MIN || pressure > BME280_PRESSURE_MAX) {
    Serial.printf("BME280 out of range: t=%ld h=%ld p=%ld\n",
                  temperature, humidity, pressure);
    return false;
  }

  out.temperature = (int16_t)temperature;
  out.humidity = (uint16_t)humidity;
  out.pressure = (uint32_t)pressure;

  Serial.printf("BME280: %.2f C  %.2f %%  %.2f hPa\n", t, h, p / 100.0f);
  return true;
}

// ---------------------------------------------------------------- network

static bool waitForWifi(uint32_t timeoutMs) {
  uint32_t start = millis();
  while (millis() - start < timeoutMs) {
    if (WiFi.status() == WL_CONNECTED) return true;
    delay(100);
  }
  return false;
}

static bool connectWifi() {
  WiFi.mode(WIFI_STA);

  if (rtcApValid) {
    // Straight to the known AP - no scan across all channels.
    WiFi.begin(WIFI_SSID, WIFI_PASS, rtcApChannel, rtcApBssid);
    if (waitForWifi(WIFI_FAST_TIMEOUT_MS)) {
      Serial.println("WiFi connected (cached AP)");
      return true;
    }
    // The AP moved channel or is gone - fall through to a full scan.
    WiFi.disconnect(true);
    rtcApValid = false;
  }

  WiFi.begin(WIFI_SSID, WIFI_PASS);
  if (!waitForWifi(WIFI_SCAN_TIMEOUT_MS)) {
    Serial.println("WiFi failed");
    return false;
  }

  memcpy(rtcApBssid, WiFi.BSSID(), sizeof(rtcApBssid));
  rtcApChannel = WiFi.channel();
  rtcApValid = true;

  Serial.println("WiFi connected (scan)");
  return true;
}

static bool syncNtp(uint32_t timeoutMs) {
  // The system clock keeps running across deep sleep on the RTC
  // oscillator, so this only has work to do after a cold boot.
  if (time(nullptr) >= EPOCH_VALID_MIN) return true;

  configTzTime(TZ_PRAGUE, "pool.ntp.org", "time.nist.gov");
  uint32_t start = millis();
  while (millis() - start < timeoutMs) {
    if (time(nullptr) >= EPOCH_VALID_MIN) return true;
    delay(200);
  }
  return false;
}

static bool postTransmission(const char* payload) {
  if (API_URL[0] == '\0') {
    Serial.println("no API URL set, keeping data buffered");
    return false;
  }

  if (BEARER_TOKEN[0] == '\0') {
    // Without a token the server answers 401 and the buffer would be kept
    // anyway - skip the radio time and say why.
    Serial.println("no API token set, keeping data buffered");
    return false;
  }

  // Certificate validity is checked against the system clock. Without a
  // synced clock the handshake fails on an expiry check with an error that
  // says nothing about the real cause, so name it here instead.
  if (time(nullptr) < EPOCH_VALID_MIN) {
    Serial.println("clock not set, cannot verify the certificate");
    return false;
  }

  WiFiClientSecure client;
  client.setCACert(ROOT_CA_BUNDLE);

  HTTPClient http;
  http.begin(client, API_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " BEARER_TOKEN);

  int code = http.POST((uint8_t*)payload, strlen(payload));

  Serial.printf("POST -> %d\n", code);

  // A negative code is a client side failure, not an HTTP status - most
  // often the TLS handshake, which is the interesting one here.
  if (code < 0) {
    Serial.printf("transport error: %s\n", http.errorToString(code).c_str());
  }

  // 422 means the payload does not match the contract. Without the body
  // there is no way to tell which field the server refused.
  if (code == 401 || code == 422) {
    Serial.println(http.getString());
  }

  http.end();
  return code >= 200 && code < 300;
}

// ---------------------------------------------------------------- sleep

static void sleepUntilNextMeasurement() {
  // Subtract the time spent awake so the cadence stays at 10 minutes
  // regardless of how long the upload took.
  uint64_t awakeUs = (uint64_t)micros();
  uint64_t sleepUs = (MEASURE_INTERVAL_US > awakeUs)
                       ? MEASURE_INTERVAL_US - awakeUs
                       : MIN_SLEEP_US;

  Serial.printf("sleeping %llu s\n", sleepUs / 1000000ULL);
  Serial.flush();

  esp_sleep_enable_timer_wakeup(sleepUs);
  esp_deep_sleep_start();
}

// ---------------------------------------------------------------- cycle

void setup() {
  Serial.begin(115200);

  // Must run before anything touches the buffer - RTC memory holds
  // garbage after a power loss.
  rtcBufferBegin();

  bme280_reading_t reading;
  bool haveMeasurement = bme280Begin() && readBme280(reading);

  bool online = connectWifi();
  if (online) {
    syncNtp(NTP_TIMEOUT_MS);
  }

  reading.timestamp = (uint32_t)time(nullptr);  // always UTC

  if (haveMeasurement && reading.timestamp >= EPOCH_VALID_MIN) {
    if (!rtcBufferAdd(reading)) {
      Serial.println("buffer full, oldest entry dropped");
    }
  } else {
    // Only happens before the very first NTP sync - an unstamped reading
    // would be useless to the server, so it is dropped rather than sent.
    Serial.println("reading discarded: no data or no clock");
  }

  Serial.printf("buffered entries: %u\n", rtcBufferCount());

  if (online && rtcBufferCount() > 0) {
    transmission_t tx;
    rtcBufferToTransmission(tx, DEVICE_ID);

    static char payload[4096];
    if (transmissionToJson(tx, payload, sizeof(payload)) && postTransmission(payload)) {
      rtcBufferClear();  // only after the server confirmed
    }
  }

  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);

  sleepUntilNextMeasurement();
}

void loop() {
  // Never reached - setup() ends in deep sleep.
}
