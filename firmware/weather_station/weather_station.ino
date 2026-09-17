#include <stdint.h>
#include <time.h>

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <esp_sntp.h>
#include <esp_system.h>
#include <esp_timer.h>
#include <esp_task_wdt.h>

#include <Wire.h>
#include <Adafruit_BME280.h>

#include <secrets.h>

#include "ca_certs.h"
#include "station_http.h"
#include "station_log.h"
#include "station_status.h"
#include "transmission_json.h"
#include "window.h"
#include "window_buffer.h"

// Reported to the server with every batch and on the LAN. Bump it with the
// firmware, so a log or a row in the record says which build wrote it.
#define FIRMWARE_VERSION "2.1.0"

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
static const uint32_t NTP_TIMEOUT_MS = 10000;

// The WiFi is nudged this often while it is down, and after WIFI_FAILOVER_MS
// of that the station moves to the other network, if there is one. Once on
// the backup it tries the primary again every WIFI_PRIMARY_RETRY_MS - the
// primary is the one the station is meant to live on.
static const uint32_t WIFI_RETRY_MS = 30000;
static const uint32_t WIFI_FAILOVER_MS = 60000;
static const uint32_t WIFI_PRIMARY_RETRY_MS = 3600000;

// A failed upload is retried this often rather than waiting for the next
// window, and this many failures in a row while associated mean the link
// is up but the uplink behind it is not - the other network gets a turn.
static const uint32_t UPLOAD_RETRY_MS = 60000;
static const uint16_t UPLOAD_FAILURES_BEFORE_SWITCH = 3;

// An hour with a backlog and no upload that worked is a state the loop has
// not found its way out of. The buffer is on the flash, so a restart costs
// nothing, and the reset reason plus the flash log say afterwards what was
// going on.
static const uint32_t NO_UPLOAD_RESTART_MS = 3600000;

// The hardware watchdog catches what the restart above cannot: a loop that
// stopped running at all, stuck in I2C or TLS. Long enough for a full
// backlog to go out, one POST after another.
static const uint32_t WATCHDOG_TIMEOUT_MS = 120000;

// POSIX TZ for Czech Republic: UTC+1, DST from last Sunday in March
// to last Sunday in October at 03:00. Used only for display.
static const char* TZ_PRAGUE = "CET-1CEST,M3.5.0,M10.5.0/3";

// How often SNTP corrects the clock once it runs. The crystal on a powered
// board drifts seconds a day rather than the minutes a week of the sleeping
// station, but a stamp lands in the record as sent, so it is kept tight.
static const uint32_t NTP_RESYNC_AFTER_S = 3600UL;

// ---------------------------------------------------------------- status

static station_status_t status;

static const char* resetReasonName(esp_reset_reason_t reason) {
  switch (reason) {
    case ESP_RST_POWERON: return "power on";
    case ESP_RST_EXT: return "external reset";
    case ESP_RST_SW: return "software restart";
    case ESP_RST_PANIC: return "panic";
    case ESP_RST_INT_WDT: return "interrupt watchdog";
    case ESP_RST_TASK_WDT: return "task watchdog";
    case ESP_RST_WDT: return "other watchdog";
    case ESP_RST_DEEPSLEEP: return "deep sleep";
    case ESP_RST_BROWNOUT: return "brownout";
    case ESP_RST_SDIO: return "sdio";
    default: return "unknown";
  }
}

// Fills in the parts that change on their own; the rest is kept up to date
// by whoever changes it.
static void refreshStatus() {
  status.firmware = FIRMWARE_VERSION;
  status.uptime_s = (uint32_t)(esp_timer_get_time() / 1000000LL);  // 64-bit, no wrap at 49 days
  status.heap_free = ESP.getFreeHeap();
  status.heap_min = ESP.getMinFreeHeap();
  status.clock_set = stationClockIsSet();
  status.online = WiFi.status() == WL_CONNECTED;
  status.buffered = windowBufferCount();

  if (status.online) {
    strncpy(status.ssid, WiFi.SSID().c_str(), sizeof(status.ssid) - 1);
    status.ssid[sizeof(status.ssid) - 1] = '\0';
    strncpy(status.ip, WiFi.localIP().toString().c_str(), sizeof(status.ip) - 1);
    status.ip[sizeof(status.ip) - 1] = '\0';
    status.rssi = (int8_t)WiFi.RSSI();
  } else {
    status.rssi = 0;
  }
}

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
    logInfo("BME280 not found");
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
    logInfo("BME280 measurement failed");
    return false;
  }

  if (isnan(t) || isnan(h) || isnan(p)) {
    logInfo("BME280 returned NaN");
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
    logInfo("BME280 out of range: t=%ld h=%ld p=%ld", temperature, humidity, pressure);
    return false;
  }

  out.timestamp = (uint32_t)time(nullptr);  // always UTC
  out.temperature = (int16_t)temperature;
  out.humidity = (uint16_t)humidity;
  out.pressure = (uint32_t)pressure;

  logTrace("BME280: %.2f C  %.2f %%  %.2f hPa", t, h, p / 100.0f);
  return true;
}

// ---------------------------------------------------------------- network

typedef struct {
  const char* ssid;
  const char* pass;
} wifi_network_t;

static const wifi_network_t WIFI_NETWORKS[] = {
  { PRIMARY_WIFI_SSID, PRIMARY_WIFI_PASS },
  { BACKUP_WIFI_SSID, BACKUP_WIFI_PASS },
};

static uint8_t wifiNetworkCount() {
  return BACKUP_WIFI_SSID[0] == '\0' ? 1 : 2;
}

static uint8_t wifiCurrent = 0;
static uint32_t wifiSwitchedMs = 0;
static uint32_t wifiKickedMs = 0;
static uint32_t wifiDownSinceMs = 0;  // when the current outage began; 0 while online
static bool wifiEverBegan = false;

// The driver's own account of the link, which is the only place the reason
// for a failed association is said: wrong password, no such network, AP
// went away. The core logs it at warning level, invisible without a debug
// build, so it is picked up here.
//
// The handler runs on the core's event task - small stack, and it can cut
// into the loop mid-line - so it only notes what happened and the loop
// writes the log (reportWifiEvents()).
static volatile bool wifiEventGotIp = false;
static volatile bool wifiEventLostIp = false;
static volatile bool wifiEventDisconnected = false;
static volatile uint8_t wifiEventReason = 0;

static void onWifiEvent(WiFiEvent_t event, WiFiEventInfo_t info) {
  switch (event) {
    case ARDUINO_EVENT_WIFI_STA_DISCONNECTED:
      wifiEventReason = info.wifi_sta_disconnected.reason;
      wifiEventDisconnected = true;
      break;
    case ARDUINO_EVENT_WIFI_STA_GOT_IP:
      wifiEventGotIp = true;
      break;
    case ARDUINO_EVENT_WIFI_STA_LOST_IP:
      wifiEventLostIp = true;
      break;
    default:
      break;
  }
}

/**
 * Writes what the driver reported since the last call.
 *
 * A disconnect is logged once per reason, not once per attempt: with the
 * network gone the core retries every couple of seconds and each try is
 * another NO_AP_FOUND, which would push the lines the flash log is for
 * out of it within the hour. The reasons the station gives itself - a
 * disconnect it asked for - are skipped altogether; there is nothing in
 * them the surrounding lines do not already say.
 */
static void reportWifiEvents() {
  static uint8_t lastReason = 0;

  if (wifiEventGotIp) {
    wifiEventGotIp = false;
    lastReason = 0;
    logInfo("WiFi: got IP %s", WiFi.localIP().toString().c_str());
  }

  if (wifiEventLostIp) {
    wifiEventLostIp = false;
    logInfo("WiFi: lost IP");
  }

  if (wifiEventDisconnected) {
    wifiEventDisconnected = false;
    uint8_t reason = wifiEventReason;

    bool selfInflicted = reason == WIFI_REASON_ASSOC_LEAVE || reason == WIFI_REASON_STA_LEAVING;
    if (!selfInflicted && reason != lastReason) {
      lastReason = reason;
      logInfo("WiFi: disconnected, reason %u (%s)", reason,
              WiFi.STA.disconnectReasonName((wifi_err_reason_t)reason));
    }
  }
}

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
  // A scan cannot start while the driver is still trying to associate -
  // it fails outright and would read as an empty sky.
  WiFi.disconnect();
  delay(200);

  int found = WiFi.scanNetworks();

  if (found < 0) {
    logInfo("scan: failed (%d)", found);
  } else if (found == 0) {
    logInfo("scan: nothing on the air");
  }

  for (int i = 0; i < found; i++) {
    const char* ours = "";
    for (uint8_t n = 0; n < wifiNetworkCount(); n++) {
      if (WiFi.SSID(i) == WIFI_NETWORKS[n].ssid) ours = "  <- ours";
    }

    logInfo("scan: %s  %ld dBm  ch %ld%s", WiFi.SSID(i).c_str(), (long)WiFi.RSSI(i), (long)WiFi.channel(i), ours);
  }

  WiFi.scanDelete();

  // Back to trying the network the scan interrupted, with the kick timer
  // restarted so the nudge does not land on the association forming.
  wifiKickedMs = millis();
  WiFi.begin(WIFI_NETWORKS[wifiCurrent].ssid, WIFI_NETWORKS[wifiCurrent].pass);
}

static void wifiConnectTo(uint8_t network) {
  wifiCurrent = network;
  wifiSwitchedMs = millis();
  wifiKickedMs = millis();
  wifiDownSinceMs = millis();
  status.wifi_network = network;

  logInfo("WiFi: joining %s (%s)", WIFI_NETWORKS[network].ssid, network == 0 ? "primary" : "backup");

  // Nothing to leave on the first join; a disconnect there only sends the
  // driver a stray event to answer while the association is forming.
  if (wifiEverBegan) {
    WiFi.disconnect();
  }
  wifiEverBegan = true;

  WiFi.begin(WIFI_NETWORKS[network].ssid, WIFI_NETWORKS[network].pass);
}

static void wifiSwitch(const char* why) {
  if (wifiNetworkCount() < 2) {
    return;
  }

  status.wifi_switches++;
  logInfo("WiFi: %s, switching network", why);
  wifiConnectTo(wifiCurrent == 0 ? 1 : 0);
}

static void wifiBegin() {
  WiFi.onEvent(onWifiEvent);
  WiFi.setHostname(STATION_HOSTNAME);
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  // The core re-associates by itself after a dropped link; the loop only
  // steps in when that has not worked for a while.
  WiFi.setAutoReconnect(true);
  wifiConnectTo(0);
}

/**
 * Keep the link up, without ever blocking the sampling for long.
 *
 * Returns whether the station is online right now. The first association
 * after boot is waited for, so the clock can be set before the first
 * reading; later drops are left to the core's own reconnect, nudged every
 * WIFI_RETRY_MS if that gets nowhere, and after WIFI_FAILOVER_MS of that
 * handed to the other network.
 */
static bool ensureWifi() {
  static bool wasConnected = false;

  if (WiFi.status() == WL_CONNECTED) {
    if (!wasConnected) {
      logInfo("WiFi connected to %s, %s, RSSI %d dBm",
              WiFi.SSID().c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
      wasConnected = true;
      wifiDownSinceMs = 0;
      stationHttpAnnounce();
    }

    // Back to the primary once it has had time to recover. Only with an
    // empty buffer, so the try costs no data - the sampling carries on
    // regardless, and the failover timer brings the backup back if the
    // primary is still down.
    if (wifiCurrent != 0 && windowBufferCount() == 0 && millis() - wifiSwitchedMs >= WIFI_PRIMARY_RETRY_MS) {
      logInfo("WiFi: trying the primary again");
      wifiConnectTo(0);
      return false;
    }

    return true;
  }

  if (wasConnected) {
    logInfo("WiFi lost");
    wasConnected = false;
    wifiDownSinceMs = millis();
  }

  // Counted from the join attempt or the drop, whichever began this
  // outage, so the first failover after boot comes a minute after the
  // first try rather than a minute after the loop got going.
  if (millis() - wifiDownSinceMs >= WIFI_FAILOVER_MS && wifiNetworkCount() > 1) {
    wifiSwitch("down too long");
    return false;
  }

  if (millis() - wifiKickedMs < WIFI_RETRY_MS) {
    return false;
  }

  wifiKickedMs = millis();
  WiFi.disconnect();
  WiFi.begin(WIFI_NETWORKS[wifiCurrent].ssid, WIFI_NETWORKS[wifiCurrent].pass);

  return false;
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
    if (ntpAnswered && stationClockIsSet()) {
      logInfo("clock synced");
      return true;
    }
    delay(200);
  }

  logInfo("NTP timed out");
  return false;
}

// Without an endpoint or a token there is nowhere to upload to: the
// windows stay buffered, and none of the failure handling - the retries,
// the network switch, the restart - has anything to say about it.
static bool uploadConfigured() {
  return API_URL[0] != '\0' && BEARER_TOKEN[0] != '\0';
}

static bool postTransmission(const char* payload) {
  WiFiClientSecure client;
  client.setCACert(ROOT_CA_BUNDLE);

  HTTPClient http;
  http.begin(client, API_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " BEARER_TOKEN);

  int code = http.POST((uint8_t*)payload, strlen(payload));

  status.last_post_code = code;
  status.last_post_at = (uint32_t)time(nullptr);

  // A negative code is a client side failure, not an HTTP status - most
  // often the TLS handshake, which is the interesting one here.
  if (code < 0) {
    logInfo("POST -> %d, transport error: %s", code, http.errorToString(code).c_str());
  } else if (code == 401 || code == 422) {
    // 422 means the payload does not match the contract. Without the body
    // there is no way to tell which field the server refused.
    logInfo("POST -> %d: %s", code, http.getString().c_str());
  } else {
    logInfo("POST -> %d", code);
  }

  http.end();
  return code >= 200 && code < 300;
}

static uint32_t lastUploadAttemptMs = 0;
static uint32_t lastUploadOkMs = 0;

/**
 * Send the backlog, oldest windows first, one batch per POST.
 *
 * Stops at the first failure and leaves the rest for the next try - the
 * server upserts on (sensor, timestamp), so a batch that was stored but
 * whose answer got lost is harmless to send again.
 */
static void uploadBuffered() {
  static char payload[8192];

  lastUploadAttemptMs = millis();

  if (!uploadConfigured()) {
    logInfo("no API URL or token set, keeping %u windows buffered", windowBufferCount());
    return;
  }

  while (windowBufferCount() > 0) {
    transmission_t tx;
    windowBufferToTransmission(tx, DEVICE_ID);
    refreshStatus();

    if (!transmissionToJson(tx, status, payload, sizeof(payload))) {
      logInfo("payload buffer too small");
      return;
    }

    if (!postTransmission(payload)) {
      status.upload_failures++;
      logInfo("keeping %u windows buffered, %u failures in a row", windowBufferCount(), status.upload_failures);

      if (status.upload_failures >= UPLOAD_FAILURES_BEFORE_SWITCH && wifiNetworkCount() > 1) {
        status.upload_failures = 0;
        wifiSwitch("uploads keep failing");
      }
      return;
    }

    status.upload_failures = 0;
    status.last_upload_ok_at = (uint32_t)time(nullptr);
    lastUploadOkMs = millis();
    windowBufferDrop(tx.data_count);
    esp_task_wdt_reset();
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

  logInfo("window %lu: t=%d [%d..%d]  h=%u [%u..%u]  p=%lu [%lu..%lu]  n=%u",
          (unsigned long)entry.timestamp,
          entry.temperature, entry.temperature_min, entry.temperature_max,
          entry.humidity, entry.humidity_min, entry.humidity_max,
          (unsigned long)entry.pressure, (unsigned long)entry.pressure_min,
          (unsigned long)entry.pressure_max,
          entry.samples);

  if (!windowBufferAdd(entry)) {
    logInfo("buffer full, oldest window dropped");
  }
}

// Checked between windows, when nothing is half measured: the buffer is on
// the flash and the next window has not begun, so the restart loses one
// reading.
static void restartIfStuck() {
  if (!uploadConfigured() || windowBufferCount() == 0 || millis() - lastUploadOkMs < NO_UPLOAD_RESTART_MS) {
    return;
  }

  logInfo("no upload for an hour with %u windows waiting, restarting", windowBufferCount());
  delay(200);
  ESP.restart();
}

static void watchdogBegin() {
  esp_task_wdt_config_t config = {
    .timeout_ms = WATCHDOG_TIMEOUT_MS,
    .idle_core_mask = 0,
    .trigger_panic = true,
  };

  // The core may have started the watchdog already, with its own timeout.
  if (esp_task_wdt_reconfigure(&config) != ESP_OK) {
    esp_task_wdt_init(&config);
  }

  esp_task_wdt_add(nullptr);
}

void setup() {
  Serial.begin(115200);

#if ARDUINO_USB_CDC_ON_BOOT
  // On a board that routes Serial through the chip's own USB, a write
  // blocks until a host drains it. Zero means write and move on.
  Serial.setTxTimeoutMs(0);
#endif

  delay(1000);  // give the serial bridge time to attach

  stationFsBegin();
  status.reset_reason = resetReasonName(esp_reset_reason());
  logInfo("weather station %s, protocol %d, reset reason: %s",
          FIRMWARE_VERSION, TRANSMISSION_VERSION, status.reset_reason);

  uint16_t restored = windowBufferLoad();
  if (restored > 0) {
    logInfo("%u windows restored from the flash", restored);
  }

  // Without the sensor there is nothing to run, and the log has said why.
  while (!bme280Begin()) {
    delay(5000);
  }

  watchdogBegin();

  wifiBegin();
  if (!waitForWifi(WIFI_TIMEOUT_MS)) {
    logInfo("WiFi failed");
    reportVisibleAps();
  }

  // After wifiBegin(): the server opens a socket, and the TCP/IP stack
  // only exists once WiFi.mode() has brought it up - before that the
  // socket call asserts on a lock that is not there yet.
  stationHttpBegin(&status, refreshStatus);
}

void loop() {
  esp_task_wdt_reset();
  stationHttpHandle();

  reportWifiEvents();
  bool online = ensureWifi();

  // A reading without a stamp is useless to the server, and the window it
  // belongs to cannot be known either - so nothing is read until the clock
  // is set. After that the clock keeps counting through a lost link.
  if (!stationClockIsSet()) {
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
        restartIfStuck();

        // A closed window goes out at once; the retry timer below covers
        // the case where it could not.
        lastUploadAttemptMs = 0;
      }

      if (!windowOpen) {
        windowBegin(window, slot);
        windowOpen = true;
      }

      windowAdd(window, reading);
    }
  }

  if (online && windowBufferCount() > 0 &&
      (lastUploadAttemptMs == 0 || millis() - lastUploadAttemptMs >= UPLOAD_RETRY_MS)) {
    uploadBuffered();
  }

  delay(100);
}
