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
#include <Adafruit_BMP280.h>
#include <Adafruit_SHT4x.h>

#include <secrets.h>

#include "ca_certs.h"
#include "noise.h"
#include "station_http.h"
#include "station_log.h"
#include "station_status.h"
#include "transmission_json.h"
#include "veml7700.h"
#include "window.h"
#include "window_buffer.h"

// Bump per flashed build; tag fw/v<version>.
#define FIRMWARE_VERSION "4.0.0"

// Board id from the IDE (build.board), sent with every batch.
#ifndef ARDUINO_BOARD
#define ARDUINO_BOARD "unknown"
#endif
#define FIRMWARE_BOARD ARDUINO_BOARD

// WROOM-32 hardware I2C (GPIO21/22); BMP280 on the base board, SHT4x at the end of the 4 m cable, same bus.
#define I2C_SDA_PIN SDA
#define I2C_SCL_PIN SCL

// 20 kHz instead of 100: the 4 m cable's capacitance and radio coupling.
#define I2C_CLOCK_HZ 20000

#define API_URL "https://weather.rajtik.com/api/v1/measurement"

// 20 readings per ten-minute window; slow enough that self-heating stays negligible.
static const uint32_t SAMPLE_INTERVAL_MS = 30000;

static const uint32_t NOISE_TAKE_WAIT_MS = 500;

static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t NTP_TIMEOUT_MS = 10000;

// Reconnect nudge; failover after this long down; retry of the primary while on the backup.
static const uint32_t WIFI_RETRY_MS = 30000;
static const uint32_t WIFI_FAILOVER_MS = 60000;
static const uint32_t WIFI_PRIMARY_RETRY_MS = 3600000;

// This many failures in a row while associated: uplink behind the AP is down, switch network.
static const uint32_t UPLOAD_RETRY_MS = 60000;
static const uint16_t UPLOAD_FAILURES_BEFORE_SWITCH = 3;

// Backlog and no successful upload for this long: restart (the buffer is on flash).
static const uint32_t NO_UPLOAD_RESTART_MS = 3600000;

// Long enough for a full backlog to go out.
static const uint32_t WATCHDOG_TIMEOUT_MS = 120000;

// Display only; stamps are UTC.
static const char* TZ_PRAGUE = "CET-1CEST,M3.5.0,M10.5.0/3";

// The crystal drifts seconds a day and the server stores stamps verbatim.
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

static void refreshStatus() {
  status.firmware = FIRMWARE_VERSION;
  status.board = FIRMWARE_BOARD;
  status.uptime_s = (uint32_t)(esp_timer_get_time() / 1000000LL);
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

static Adafruit_BMP280 bmp(&Wire);
static Adafruit_SHT4x sht;

// Pressure only: it sits indoors, so its temperature is never sent.
static bool bmp280Begin() {
  // Address depends on the SDO strap.
  if (!bmp.begin(0x76) && !bmp.begin(0x77)) {
    logInfo("BMP280 not found");
    return false;
  }

  bmp.setSampling(Adafruit_BMP280::MODE_FORCED,
                  Adafruit_BMP280::SAMPLING_X1,  // temperature, needed for the pressure compensation
                  Adafruit_BMP280::SAMPLING_X1,
                  Adafruit_BMP280::FILTER_OFF);
  return true;
}

static bool sht4xBegin() {
  if (!sht.begin(&Wire)) {
    logInfo("SHT4x not found");
    return false;
  }

  sht.setPrecision(SHT4X_HIGH_PRECISION);
  sht.setHeater(SHT4X_NO_HEATER);

  // The first read after begin() often fails; spend it here.
  sensors_event_t humidity, temp;
  sht.getEvent(&humidity, &temp);
  return true;
}

static bool sensorsBegin() {
  Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
  Wire.setClock(I2C_CLOCK_HZ);
  bool bmpFound = bmp280Begin();
  bool shtFound = sht4xBegin();

  // Not waited for; vemlRead() keeps trying.
  vemlBegin();
  return bmpFound && shtFound;
}

static bool readSensors(station_reading_t& out) {
  // All I2C traffic happens with the mic clocks stopped.
  sensors_event_t humidityEvent, tempEvent;
  float p = NAN;
  uint32_t illuminance = 0;
  noiseHush();
  const bool shtRead = sht.getEvent(&humidityEvent, &tempEvent);
  const bool bmpRead = shtRead && bmp.takeForcedMeasurement();
  if (bmpRead) {
    p = bmp.readPressure();                      // Pa, the unit the API takes
  }
  const bool lightRead = vemlRead(illuminance);
  noiseUnhush();

  if (!shtRead) {
    logInfo("SHT4x measurement failed");
    return false;
  }

  if (!bmpRead) {
    logInfo("BMP280 measurement failed");
    return false;
  }

  float t = tempEvent.temperature;               // degC
  float h = humidityEvent.relative_humidity;     // %

  if (isnan(t) || isnan(h) || isnan(p)) {
    logInfo("sensors returned NaN: t=%.2f h=%.2f p=%.0f", t, h, p);
    return false;
  }

  long temperature = lroundf(t * 100.0f);
  long humidity = lroundf(h * 100.0f);
  long pressure = lroundf(p);

  // Out of range would get the whole batch rejected and block the queue.
  if (temperature < READING_TEMP_MIN || temperature > READING_TEMP_MAX ||
      humidity < READING_HUMIDITY_MIN || humidity > READING_HUMIDITY_MAX ||
      pressure < READING_PRESSURE_MIN || pressure > READING_PRESSURE_MAX) {
    logInfo("reading out of range: t=%ld h=%ld p=%ld", temperature, humidity, pressure);
    return false;
  }

  out.timestamp = (uint32_t)time(nullptr);  // UTC
  out.temperature = (int16_t)temperature;
  out.humidity = (uint16_t)humidity;
  out.pressure = (uint32_t)pressure;

  // Out-of-range light is dropped; the rest of the reading stays.
  out.has_illuminance = lightRead && illuminance <= READING_ILLUMINANCE_MAX;
  out.illuminance = out.has_illuminance ? illuminance : 0;

  if (out.has_illuminance) {
    logTrace("SHT4x: %.2f C  %.2f %%  BMP280: %.2f hPa  VEML7700: %.2f lx", t, h, p / 100.0f, illuminance / 100.0f);
  } else {
    logTrace("SHT4x: %.2f C  %.2f %%  BMP280: %.2f hPa  VEML7700: none", t, h, p / 100.0f);
  }
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

static bool otaListening = false;

static uint8_t wifiCurrent = 0;
static uint32_t wifiSwitchedMs = 0;
static uint32_t wifiKickedMs = 0;
static uint32_t wifiDownSinceMs = 0;  // when the current outage began; 0 while online
static bool wifiEverBegan = false;

// Disconnect reason is the only place a wrong password or missing AP shows up.
// The handler runs on the event task: it only notes, the loop logs (reportWifiEvents()).
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

// Once per reason: the core retries every few seconds and would flush the flash log.
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

// -70 dBm is fine, -80 marginal, past -85 it associates but a TLS upload does not survive.
static void reportVisibleAps() {
  // A scan fails while associating.
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

  // Resume the interrupted network; restart the kick timer so the nudge does not hit the forming association.
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

  // Not on the first join: a stray event while the association forms.
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
  WiFi.setAutoReconnect(true);
  wifiConnectTo(0);
}

// Returns whether online. Only the first association is waited for; later drops are nudged, then failed over.
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

    // Only with an empty buffer so the try costs no data.
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

static volatile bool clockStepPending = false;
static volatile int32_t clockStepMs = 0;
static volatile uint32_t clockStepOverS = 0;

// Replaces the stock sntp_sync_time() to read the clock before stepping it; the step is the drift.
// The first answer after boot is skipped (steps from 1970, or an RTC clock of unknown age).
// Runs on lwIP's task: no logging here.
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

static void reportClockStep() {
  if (!clockStepPending) {
    return;
  }

  clockStepPending = false;
  logInfo("clock stepped %+ld ms after %lu s", (long)clockStepMs, (unsigned long)clockStepOverS);
}

// Whenever online, never gated on the clock: it survives a software restart in the RTC.
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

// Waits on the SNTP answer, not on the clock looking plausible.
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

  // Negative: client side failure, most often the TLS handshake.
  if (code < 0) {
    // The largest free block says whether the TLS buffers fitted.
    char tlsError[96];
    int tlsCode = client.lastError(tlsError, sizeof(tlsError));
    logInfo("POST -> %d, transport error: %s; TLS %d: %s; largest free block %u",
            code, http.errorToString(code).c_str(), tlsCode, tlsError, (unsigned)ESP.getMaxAllocHeap());
  } else if (code == 401 || code == 422) {
    // 422: payload does not match the contract; the body names the field.
    logInfo("POST -> %d: %s", code, http.getString().c_str());
  } else {
    logInfo("POST -> %d", code);
  }

  http.end();
  return code >= 200 && code < 300;
}

static uint32_t lastUploadAttemptMs = 0;
static uint32_t lastUploadOkMs = 0;

static void uploadBatches(char* payload, size_t payloadLen);

// Oldest first, stops at the first failure. The server upserts: resending is safe.
static void uploadBuffered() {
  static char payload[TRANSMISSION_PAYLOAD_BYTES];

  lastUploadAttemptMs = millis();

  if (!uploadConfigured()) {
    logInfo("no API URL or token set, keeping %u windows buffered", windowBufferCount());
    return;
  }

  // TLS needs the noise task's buffers.
  noisePause();
  uploadBatches(payload, sizeof(payload));
  noiseResume();
}

static void uploadBatches(char* payload, size_t payloadLen) {
  while (windowBufferCount() > 0) {
    transmission_t tx;
    windowBufferToTransmission(tx, DEVICE_ID);
    refreshStatus();

    if (!transmissionToJson(tx, status, payload, payloadLen)) {
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
  station_window_t entry;

  if (!windowClose(window, entry)) {
    return;
  }

  // The noise task closes a slot on its first frame past the boundary.
  if (noiseTake(window.slot, entry.noise, NOISE_TAKE_WAIT_MS)) {
    logInfo("noise %lu: LAeq %d  LAmax %d  LA10 %d  LA90 %d  (0.01 dB)  s=%u",
            (unsigned long)window.slot, entry.noise.laeq, entry.noise.lamax,
            entry.noise.la10, entry.noise.la90, entry.noise.seconds);
  } else {
    noise_stats_t n = noiseStats();
    logInfo("noise %lu: none (running %d, frames %lu, silent %lu, short reads %lu)",
            (unsigned long)window.slot, n.running, (unsigned long)n.frames,
            (unsigned long)n.silent_frames, (unsigned long)n.short_reads);
  }

  logInfo("window %lu: t=%d [%d..%d]  h=%u [%u..%u]  p=%lu [%lu..%lu]  l=%lu [%lu..%lu]/%u  n=%u",
          (unsigned long)entry.timestamp,
          entry.temperature, entry.temperature_min, entry.temperature_max,
          entry.humidity, entry.humidity_min, entry.humidity_max,
          (unsigned long)entry.pressure, (unsigned long)entry.pressure_min,
          (unsigned long)entry.pressure_max,
          (unsigned long)entry.illuminance, (unsigned long)entry.illuminance_min,
          (unsigned long)entry.illuminance_max, entry.illuminance_samples,
          entry.samples);

  if (!windowBufferAdd(entry)) {
    logInfo("buffer full, oldest window dropped");
  }
}

static void restartIfStuck() {
  if (!uploadConfigured() || windowBufferCount() == 0 || millis() - lastUploadOkMs < NO_UPLOAD_RESTART_MS) {
    return;
  }

  logInfo("no upload for an hour with %u windows waiting, restarting", windowBufferCount());
  delay(200);
  ESP.restart();
}

// ---------------------------------------------------------------- ota

// OTA blocks the loop: the progress callback feeds the watchdog, a stalled transfer still trips it.
// Off without a password.
static void otaBegin() {
  if (OTA_PASSWORD[0] == '\0') {
    logInfo("no OTA password set, OTA off");
    return;
  }

  ArduinoOTA.setHostname(STATION_HOSTNAME);
  ArduinoOTA.setPort(STATION_OTA_PORT);
  ArduinoOTA.setPassword(OTA_PASSWORD);
  // The HTTP module owns mDNS and advertises OTA too.
  ArduinoOTA.setMdnsEnabled(false);

  ArduinoOTA.onStart([]() {
    logInfo("OTA: update starting");
    if (windowOpen) {
      closeWindow();
      windowOpen = false;
    }
    noisePause();
  });
  ArduinoOTA.onProgress([](unsigned int, unsigned int) {
    esp_task_wdt_reset();
  });
  ArduinoOTA.onEnd([]() {
    logInfo("OTA: update written, restarting");
  });
  ArduinoOTA.onError([](ota_error_t error) {
    logInfo("OTA: failed (%u), staying on %s", (unsigned)error, FIRMWARE_VERSION);
    noiseResume();
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

  // The core may have started it with its own timeout.
  if (esp_task_wdt_reconfigure(&config) != ESP_OK) {
    esp_task_wdt_init(&config);
  }

  esp_task_wdt_add(nullptr);
}

void setup() {
  Serial.begin(115200);

#if ARDUINO_USB_CDC_ON_BOOT
  // Native USB Serial blocks until a host drains it.
  Serial.setTxTimeoutMs(0);
#endif

  delay(1000);  // serial bridge attach

  stationFsBegin();
  status.reset_reason = resetReasonName(esp_reset_reason());
  logInfo("weather station %s on %s, protocol %d, reset reason: %s",
          FIRMWARE_VERSION, FIRMWARE_BOARD, TRANSMISSION_VERSION, status.reset_reason);

  uint16_t restored = windowBufferLoad();
  if (restored > 0) {
    logInfo("%u windows restored from the flash", restored);
  }

  while (!sensorsBegin()) {
    delay(5000);
  }

  // Runs on without the microphone.
  noiseBegin();

  watchdogBegin();

  wifiBegin();
  if (!waitForWifi(WIFI_TIMEOUT_MS)) {
    logInfo("WiFi failed");
    reportVisibleAps();
  }

  // After wifiBegin(): the TCP/IP stack must exist before the socket (asserts on a missing lock).
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

  // No reading until the clock is set: the window is unknown without it.
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

    station_reading_t reading;
    if (readSensors(reading)) {
      // Off the reading's own stamp: an SNTP step can cross a slot boundary.
      uint32_t slot = windowSlotOf(reading.timestamp);

      if (windowOpen && slot != window.slot) {
        closeWindow();
        windowOpen = false;
        restartIfStuck();

        lastUploadAttemptMs = 0;
      }

      if (!windowOpen) {
        windowBegin(window, slot);
        windowOpen = true;
      }

      windowAdd(window, reading);
    }
  }

  static uint32_t lastNoiseRetryMs = 0;
  if (millis() - lastNoiseRetryMs >= NOISE_RETRY_MS) {
    lastNoiseRetryMs = millis();
    noiseResume();
  }

  if (online && windowBufferCount() > 0 &&
      (lastUploadAttemptMs == 0 || millis() - lastUploadAttemptMs >= UPLOAD_RETRY_MS)) {
    uploadBuffered();
  }

  delay(100);
}
