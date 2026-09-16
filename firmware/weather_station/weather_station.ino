#include <stdint.h>
#include <time.h>

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <esp_sntp.h>

#include <Wire.h>
#include <Adafruit_BME280.h>

#include <secrets.h>

#include "ca_certs.h"
#include "transmission_json.h"
#include "window.h"
#include "window_buffer.h"

// Hardware I2C of the ESP32-C3-DevKitM-1 as the board variant defines it:
// SDA on GPIO8, SCL on GPIO9. Change to match the wiring.
#define BME280_SDA_PIN SDA
#define BME280_SCL_PIN SCL

// The DevKitM-1 hangs its WS2812 RGB LED on GPIO8 - the same pin as SDA.
// The LED reads every I2C transfer as its own data and, whenever the line
// sits low long enough to latch, lights up in whatever colour the bytes
// happened to spell, usually full white. With this on, the LED is blanked
// after every transfer. Set to 0 once the LED is cut off the board.
#define RGB_LED_ON_SDA 1

// Time for the die to shed the heat begin() puts into it. See bme280Begin().
#define BME280_SETTLE_MS 500

// Full endpoint, e.g. "https://weather.example.com/api/v1/measurement".
#define API_URL "https://weather.rajtik.com/api/v1/measurement"

// One reading every half minute, twenty to a window. Fast enough to catch
// a gust or a cloud crossing the sun, slow enough that the sensor's own
// self-heating stays negligible in forced mode.
static const uint32_t SAMPLE_INTERVAL_MS = 30000;

static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t WIFI_RETRY_MS = 30000;
static const uint32_t NTP_TIMEOUT_MS = 10000;

// POSIX TZ for Czech Republic: UTC+1, DST from last Sunday in March
// to last Sunday in October at 03:00. Used only for display.
static const char* TZ_PRAGUE = "CET-1CEST,M3.5.0,M10.5.0/3";

// Sanity threshold: any epoch below this means the clock is not set.
static const uint32_t EPOCH_VALID_MIN = 1700000000UL;

// How often SNTP corrects the clock once it runs. The crystal on a powered
// board drifts seconds a day rather than the minutes a week of the sleeping
// station, but a stamp lands in the record as sent, so it is kept tight.
static const uint32_t NTP_RESYNC_AFTER_S = 3600UL;

// ---------------------------------------------------------------- sensors

static Adafruit_BME280 bme;

// See RGB_LED_ON_SDA. Wire has to let go of the pin for the LED write and
// take it back afterwards - the peripheral manager hands a pin to one
// driver at a time, and a write while I2C holds it goes nowhere.
static void quietSharedLed() {
#if RGB_LED_ON_SDA && defined(RGB_BUILTIN)
  Wire.end();
  rgbLedWrite(RGB_BUILTIN, 0, 0, 0);
  Wire.begin(BME280_SDA_PIN, BME280_SCL_PIN);
#endif
}

static bool bme280Begin() {
  Wire.begin(BME280_SDA_PIN, BME280_SCL_PIN);

  // Boards ship with either address depending on how SDO is strapped.
  if (!bme.begin(0x76, &Wire) && !bme.begin(0x77, &Wire)) {
    Serial.println("BME280 not found");
    quietSharedLed();
    return false;
  }

  // Forced mode: the sensor takes one measurement on demand and sleeps
  // between them, so it does not heat itself between samples. Oversampling
  // and filtering stay off - the window average does that job, and it does
  // it on readings half a minute apart rather than milliseconds.
  bme.setSampling(Adafruit_BME280::MODE_FORCED,
                  Adafruit_BME280::SAMPLING_X1,  // temperature
                  Adafruit_BME280::SAMPLING_X1,  // pressure
                  Adafruit_BME280::SAMPLING_X1,  // humidity
                  Adafruit_BME280::FILTER_OFF);

  // begin() runs the sensor in normal mode at 16x oversampling for over
  // 100 ms before this switches it to forced, and that warms the die by
  // about 0.1 degC. Measured, the reading is back within 0.02 degC of
  // ambient after ~350 ms and within 0.01 degC after ~700 ms.
  delay(BME280_SETTLE_MS);

  // The data registers hold the previous conversion until a new one
  // finishes, and the reset inside begin() leaves no previous conversion -
  // the first forced read would return power-on defaults, not a
  // measurement. This throwaway conversion fills them with a real one.
  bme.takeForcedMeasurement();
  quietSharedLed();

  return true;
}

static bool readBme280(bme280_reading_t& out) {
  bool ok = bme.takeForcedMeasurement();

  float t = bme.readTemperature();  // degC
  float h = bme.readHumidity();     // %
  float p = bme.readPressure();     // Pa, already the unit the API takes

  quietSharedLed();

  if (!ok) {
    Serial.println("BME280 measurement failed");
    return false;
  }

  if (isnan(t) || isnan(h) || isnan(p)) {
    Serial.println("BME280 returned NaN");
    return false;
  }

  long temperature = lroundf(t * 100.0f);
  long humidity = lroundf(h * 100.0f);
  long pressure = lroundf(p);

  // The server validates every entry and rejects the whole batch when one
  // value is out of range. A single bad reading would pull a window's
  // extreme outside the range and block every window behind it, so it is
  // dropped here instead.
  if (temperature < BME280_TEMP_MIN || temperature > BME280_TEMP_MAX ||
      humidity < BME280_HUMIDITY_MIN || humidity > BME280_HUMIDITY_MAX ||
      pressure < BME280_PRESSURE_MIN || pressure > BME280_PRESSURE_MAX) {
    Serial.printf("BME280 out of range: t=%ld h=%ld p=%ld\n",
                  temperature, humidity, pressure);
    return false;
  }

  out.timestamp = (uint32_t)time(nullptr);  // always UTC
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

/**
 * List what the radio can hear, after a failure to associate.
 *
 * "WiFi failed" alone cannot tell a wrong password from a site where the AP
 * is simply out of reach. The number is what settles it: around -70 dBm is
 * comfortable, -80 is marginal, past -85 an association may still form but
 * will not survive a TLS upload.
 */
static void reportVisibleAps() {
  int found = WiFi.scanNetworks();

  if (found <= 0) {
    Serial.println("scan: nothing on the air");
    return;
  }

  for (int i = 0; i < found; i++) {
    Serial.printf("scan: %s  %d dBm  ch %d%s\n",
                  WiFi.SSID(i).c_str(),
                  WiFi.RSSI(i),
                  WiFi.channel(i),
                  WiFi.SSID(i) == WIFI_SSID ? "  <- ours" : "");
  }

  WiFi.scanDelete();
}

static void wifiBegin() {
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  // The core re-associates by itself after a dropped link; the loop only
  // steps in when that has not worked for a while.
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
}

/**
 * Keep the link up, without ever blocking the sampling for long.
 *
 * Returns whether the station is online right now. The first association
 * after boot is waited for, so the clock can be set before the first
 * reading; later drops are left to the core's own reconnect and nudged
 * once every WIFI_RETRY_MS if that gets nowhere.
 */
static bool ensureWifi() {
  static uint32_t lastAttemptMs = 0;
  static bool wasConnected = false;

  if (WiFi.status() == WL_CONNECTED) {
    if (!wasConnected) {
      Serial.printf("WiFi connected, RSSI %d dBm\n", WiFi.RSSI());
      wasConnected = true;
    }
    return true;
  }

  if (wasConnected) {
    Serial.println("WiFi lost");
    wasConnected = false;
  }

  if (lastAttemptMs != 0 && millis() - lastAttemptMs < WIFI_RETRY_MS) {
    return false;
  }

  lastAttemptMs = millis();
  WiFi.disconnect();
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  return false;
}

static bool clockIsSet() {
  return time(nullptr) >= EPOCH_VALID_MIN;
}

static volatile bool ntpAnswered = false;

static void onNtpSync(struct timeval*) {
  ntpAnswered = true;
}

/**
 * Start SNTP and wait for its first answer.
 *
 * Waits on the sync callback rather than on the clock looking plausible:
 * that would let a later call return before the server had answered. Once
 * running, SNTP corrects the clock on its own every NTP_RESYNC_AFTER_S
 * while the link is up - there is nothing to re-arm.
 */
static bool syncClock(uint32_t timeoutMs) {
  static bool started = false;

  if (!started) {
    sntp_set_time_sync_notification_cb(onNtpSync);
    esp_sntp_set_sync_interval(NTP_RESYNC_AFTER_S * 1000UL);
    sntp_set_sync_mode(SNTP_SYNC_MODE_IMMED);
    configTzTime(TZ_PRAGUE, "pool.ntp.org", "time.nist.gov");
    started = true;
  }

  uint32_t start = millis();
  while (millis() - start < timeoutMs) {
    if (ntpAnswered && clockIsSet()) {
      Serial.println("clock synced");
      return true;
    }
    delay(200);
  }

  Serial.println("NTP timed out");
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

/**
 * Send the backlog, oldest windows first, one batch per POST.
 *
 * Stops at the first failure and leaves the rest for the next window - the
 * server upserts on (sensor, timestamp), so a batch that was stored but
 * whose answer got lost is harmless to send again.
 */
static void uploadBuffered() {
  static char payload[8192];

  while (windowBufferCount() > 0) {
    transmission_t tx;
    windowBufferToTransmission(tx, DEVICE_ID);

    if (!transmissionToJson(tx, payload, sizeof(payload))) {
      Serial.println("payload buffer too small");
      return;
    }

    if (!postTransmission(payload)) {
      Serial.printf("keeping %u windows buffered\n", windowBufferCount());
      return;
    }

    windowBufferDrop(tx.data_count);
  }
}

// ---------------------------------------------------------------- cycle

static window_t window;
static bool windowOpen = false;
static uint32_t lastSampleMs = 0;

static void closeWindow() {
  bme280_window_t entry;

  if (!windowClose(window, entry)) {
    return;
  }

  Serial.printf("window %lu: t=%d [%d..%d]  h=%u [%u..%u]  p=%lu [%lu..%lu]  n=%u\n",
                (unsigned long)entry.timestamp,
                entry.temperature, entry.temperature_min, entry.temperature_max,
                entry.humidity, entry.humidity_min, entry.humidity_max,
                (unsigned long)entry.pressure, (unsigned long)entry.pressure_min,
                (unsigned long)entry.pressure_max,
                entry.samples);

  if (!windowBufferAdd(entry)) {
    Serial.println("buffer full, oldest window dropped");
  }
}

void setup() {
  Serial.begin(115200);

#if ARDUINO_USB_CDC_ON_BOOT
  // On a board that routes Serial through the chip's own USB, a write
  // blocks until a host drains it. Zero means write and move on.
  Serial.setTxTimeoutMs(0);
#endif

  delay(1000);  // give the serial bridge time to attach
  Serial.println("weather station, protocol 2");

  // Without the sensor there is nothing to run, and the log has said why.
  while (!bme280Begin()) {
    delay(5000);
  }

  wifiBegin();
  if (!waitForWifi(WIFI_TIMEOUT_MS)) {
    Serial.println("WiFi failed");
    reportVisibleAps();
  }
}

void loop() {
  bool online = ensureWifi();

  // A reading without a stamp is useless to the server, and the window it
  // belongs to cannot be known either - so nothing is read until the clock
  // is set. After that the clock keeps counting through a lost link.
  if (!clockIsSet()) {
    if (online) {
      syncClock(NTP_TIMEOUT_MS);
    } else {
      delay(1000);
    }
    return;
  }

  if (lastSampleMs == 0 || millis() - lastSampleMs >= SAMPLE_INTERVAL_MS) {
    lastSampleMs = millis();

    bme280_reading_t reading;
    if (readBme280(reading)) {
      // The slot comes off the reading's own stamp, not off the clock at
      // the top of the loop: an SNTP step or the measurement itself can
      // cross a slot boundary in between, and a reading filed into the
      // window before its own would carry that window's stamp into the
      // next bucket.
      uint32_t slot = windowSlotOf(reading.timestamp);

      if (windowOpen && slot != window.slot) {
        closeWindow();
        windowOpen = false;

        if (online) {
          uploadBuffered();
        }
      }

      if (!windowOpen) {
        windowBegin(window, slot);
        windowOpen = true;
      }

      windowAdd(window, reading);
    }
  }

  delay(100);
}
