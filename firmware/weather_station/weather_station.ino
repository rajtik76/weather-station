#include <stdint.h>
#include <time.h>

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoOTA.h>
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

// Sent with every batch; bump it with each build that goes on a board, and
// tag the commit fw/v<version>. The history is firmware/CHANGELOG.md.
#define FIRMWARE_VERSION "2.3.0"

// The IDE's board selection (build.board in boards.txt), e.g. ESP32C3_DEV,
// DFROBOT_FIREBEETLE_2_ESP32C6, ESP32_DEV. Rides with every batch so the
// server can tell which hardware sent what after a board swap.
#ifndef ARDUINO_BOARD
#define ARDUINO_BOARD "unknown"
#endif
#define FIRMWARE_BOARD ARDUINO_BOARD

// ESP32-C3-DevKitM-1 hardware I2C: SDA GPIO8, SCL GPIO9.
#define BME280_SDA_PIN SDA
#define BME280_SCL_PIN SCL

// The DevKitM-1's WS2812 LED shares GPIO8 with SDA and latches I2C traffic
// as colour data. With this on, the LED is blanked after every transfer.
// Set to 0 once the LED is cut off the board.
#define RGB_LED_ON_SDA 1

// Time for the die to cool after begin(). See bme280Begin().
#define BME280_SETTLE_MS 500

#define API_URL "https://weather.rajtik.com/api/v1/measurement"

// Twenty readings to a ten-minute window. Slow enough that self-heating
// in forced mode stays negligible.
static const uint32_t SAMPLE_INTERVAL_MS = 30000;

static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t NTP_TIMEOUT_MS = 10000;

// Reconnect nudge while down; failover to the other network after that
// long down; retry of the primary while on the backup.
static const uint32_t WIFI_RETRY_MS = 30000;
static const uint32_t WIFI_FAILOVER_MS = 60000;
static const uint32_t WIFI_PRIMARY_RETRY_MS = 3600000;

// This many failures in a row while associated means the uplink behind
// the AP is down, and the other network gets a turn.
static const uint32_t UPLOAD_RETRY_MS = 60000;
static const uint16_t UPLOAD_FAILURES_BEFORE_SWITCH = 3;

// An hour with a backlog and no successful upload: restart. The buffer is
// on the flash, so it costs nothing, and the flash log says what happened.
static const uint32_t NO_UPLOAD_RESTART_MS = 3600000;

// For a loop stuck in I2C or TLS. Long enough for a full backlog to go out.
static const uint32_t WATCHDOG_TIMEOUT_MS = 120000;

// Display only; stamps are UTC.
static const char* TZ_PRAGUE = "CET-1CEST,M3.5.0,M10.5.0/3";

// The crystal drifts seconds a day and a stamp lands in the record as sent.
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
  status.board = FIRMWARE_BOARD;
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

// See RGB_LED_ON_SDA. Wire has to release the pin for the LED write; the
// peripheral manager hands a pin to one driver at a time.
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

  // Forced mode: no self-heating between samples. No oversampling or
  // filtering; the window average does that on half-minute readings.
  bme.setSampling(Adafruit_BME280::MODE_FORCED,
                  Adafruit_BME280::SAMPLING_X1,  // temperature
                  Adafruit_BME280::SAMPLING_X1,  // pressure
                  Adafruit_BME280::SAMPLING_X1,  // humidity
                  Adafruit_BME280::FILTER_OFF);

  // begin() runs normal mode at 16x oversampling for >100 ms, which warms
  // the die ~0.1 degC. Measured: back within 0.02 degC after ~350 ms.
  delay(BME280_SETTLE_MS);

  // After the reset in begin() the data registers hold power-on defaults;
  // the first forced read would return those.
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

  // One out-of-range extreme would have the server reject the whole batch
  // and block every window behind it.
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

// Set by otaBegin(); the mDNS announcement reads it.
static bool otaListening = false;

static uint8_t wifiCurrent = 0;
static uint32_t wifiSwitchedMs = 0;
static uint32_t wifiKickedMs = 0;
static uint32_t wifiDownSinceMs = 0;  // when the current outage began; 0 while online
static bool wifiEverBegan = false;

// The driver's disconnect reason is the only place a wrong password or a
// missing AP is said, and the core logs it below the release log level.
// The handler runs on the event task, so it only notes and the loop logs
// (reportWifiEvents()).
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

// Once per reason, not per attempt: the core retries every few seconds and
// would flush the flash log within the hour. Self-inflicted disconnects
// are skipped.
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

// Tells a wrong password from an AP out of reach: -70 dBm is fine, -80
// marginal, past -85 an association forms but a TLS upload does not survive.
static void reportVisibleAps() {
  // A scan fails outright while the driver is associating.
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

// Returns whether the station is online. Only the first association is
// waited for, so the clock can be set before the first reading; later
// drops are the core's to reconnect, nudged and then failed over.
static bool ensureWifi() {
  static bool wasConnected = false;

  if (WiFi.status() == WL_CONNECTED) {
    if (!wasConnected) {
      logInfo("WiFi connected to %s, %s, RSSI %d dBm",
              WiFi.SSID().c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
      wasConnected = true;
      wifiDownSinceMs = 0;
      stationHttpAnnounce(otaListening);
    }

    // Back to the primary, only with an empty buffer so the try costs no data.
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

  // Counted from the join attempt or the drop that began the outage.
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

// ---------------------------------------------------------------- clock

static volatile bool ntpAnswered = false;

// Noted by the override below, logged by reportClockStep() from the loop.
static volatile bool clockStepPending = false;
static volatile int32_t clockStepMs = 0;
static volatile uint32_t clockStepOverS = 0;

// Replaces the stock sntp_sync_time(), which steps the clock before the
// callback sees the old reading. The step is the drift since the previous
// sync. The first answer after boot is not counted: a cold boot steps from
// 1970 and a soft reset's RTC clock is of unknown age. Runs on lwIP's task:
// no logging here.
extern "C" void sntp_sync_time(struct timeval* tv) {
  struct timeval before;
  gettimeofday(&before, nullptr);
  settimeofday(tv, nullptr);

  uint32_t previousSyncAt = status.clock_synced_at;
  status.clock_synced_at = (uint32_t)tv->tv_sec;

  if (previousSyncAt != 0) {
    int64_t stepUs = (int64_t)(tv->tv_sec - before.tv_sec) * 1000000LL + (tv->tv_usec - before.tv_usec);
    int32_t stepMs = (int32_t)(stepUs / 1000);

    status.clock_step_ms = stepMs;
    status.clock_step_over_s = (uint32_t)tv->tv_sec - previousSyncAt;
    if (labs(stepMs) > labs(status.clock_step_max_ms)) {
      status.clock_step_max_ms = stepMs;
    }

    clockStepMs = stepMs;
    clockStepOverS = status.clock_step_over_s;
    clockStepPending = true;
  }

  ntpAnswered = true;
}

// Writes the correction the override noted, from the loop.
static void reportClockStep() {
  if (!clockStepPending) {
    return;
  }

  clockStepPending = false;
  logInfo("clock stepped %+ld ms after %lu s", (long)clockStepMs, (unsigned long)clockStepOverS);
}

// Called whenever online, not only while the clock is unset: the clock
// survives a software restart in the RTC, and a start gated on it would
// never happen on such a boot.
static void sntpBegin() {
  static bool started = false;

  if (started) {
    return;
  }

  esp_sntp_set_sync_interval(NTP_RESYNC_AFTER_S * 1000UL);
  sntp_set_sync_mode(SNTP_SYNC_MODE_IMMED);
  configTzTime(TZ_PRAGUE, "pool.ntp.org", "time.nist.gov");
  started = true;
}

// Waits on the answer, not on the clock looking plausible.
static bool syncClock(uint32_t timeoutMs) {
  sntpBegin();

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

// ---------------------------------------------------------------- upload

// Unconfigured: windows stay buffered and no failure handling applies.
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

// Oldest first, one batch per POST, stops at the first failure. The server
// upserts, so a batch whose answer got lost is safe to send again.
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

// Between windows, so the restart loses one reading at most.
static void restartIfStuck() {
  if (!uploadConfigured() || windowBufferCount() == 0 || millis() - lastUploadOkMs < NO_UPLOAD_RESTART_MS) {
    return;
  }

  logInfo("no upload for an hour with %u windows waiting, restarting", windowBufferCount());
  delay(200);
  ESP.restart();
}

// ---------------------------------------------------------------- ota

// The open window is closed into the buffer first. The upload blocks the
// loop, so the watchdog is fed from the progress callback; a stalled
// transfer still trips it. Off without a password.
static void otaBegin() {
  if (OTA_PASSWORD[0] == '\0') {
    logInfo("no OTA password set, OTA off");
    return;
  }

  ArduinoOTA.setHostname(STATION_HOSTNAME);
  ArduinoOTA.setPort(STATION_OTA_PORT);
  ArduinoOTA.setPassword(OTA_PASSWORD);
  // The HTTP module owns mDNS and re-announces on every association;
  // it advertises the OTA service alongside its own.
  ArduinoOTA.setMdnsEnabled(false);

  ArduinoOTA.onStart([]() {
    logInfo("OTA: update starting");
    if (windowOpen) {
      closeWindow();
      windowOpen = false;
    }
  });
  ArduinoOTA.onProgress([](unsigned int, unsigned int) {
    esp_task_wdt_reset();
  });
  ArduinoOTA.onEnd([]() {
    logInfo("OTA: update written, restarting");
  });
  ArduinoOTA.onError([](ota_error_t error) {
    logInfo("OTA: failed (%u), staying on %s", (unsigned)error, FIRMWARE_VERSION);
  });

  ArduinoOTA.begin();
  otaListening = true;
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
  logInfo("weather station %s on %s, protocol %d, reset reason: %s",
          FIRMWARE_VERSION, FIRMWARE_BOARD, TRANSMISSION_VERSION, status.reset_reason);

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
  otaBegin();
}

void loop() {
  esp_task_wdt_reset();
  stationHttpHandle();
  ArduinoOTA.handle();

  reportWifiEvents();
  reportClockStep();
  bool online = ensureWifi();

  if (online) {
    sntpBegin();
  }

  // No reading until the clock is set; its window cannot be known otherwise.
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
      // Off the reading's own stamp: an SNTP step or the measurement
      // itself can cross a slot boundary since the top of the loop.
      uint32_t slot = windowSlotOf(reading.timestamp);

      if (windowOpen && slot != window.slot) {
        closeWindow();
        windowOpen = false;
        restartIfStuck();

        // Send at once; the retry timer covers a failure.
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
