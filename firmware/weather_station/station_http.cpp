#include "station_http.h"

#include <time.h>

#include <ESPmDNS.h>
#include <LittleFS.h>
#include <WebServer.h>

#include "station_log.h"

static WebServer server(80);
static const station_status_t* current = nullptr;
static void (*refreshStatus)() = nullptr;

static void formatEpoch(uint32_t epoch, char* out, size_t outLen) {
  if (epoch == 0) {
    strncpy(out, "never", outLen);
    out[outLen - 1] = '\0';
    return;
  }

  time_t t = epoch;
  struct tm local;
  localtime_r(&t, &local);
  strftime(out, outLen, "%Y-%m-%d %H:%M:%S", &local);
}

static void sendSummary() {
  refreshStatus();
  const station_status_t& s = *current;

  char lastPost[24], lastOk[24], synced[24];
  formatEpoch(s.last_post_at, lastPost, sizeof(lastPost));
  formatEpoch(s.last_upload_ok_at, lastOk, sizeof(lastOk));
  formatEpoch(s.clock_synced_at, synced, sizeof(synced));

  char body[1024];
  snprintf(body, sizeof(body),
           "weather station %s\n"
           "\n"
           "uptime          %lu s\n"
           "reset reason    %s\n"
           "clock           %s, synced %s\n"
           "clock drift     %+ld ms over %lu s, worst %+ld ms since boot\n"
           "heap            %lu free, %lu lowest\n"
           "\n"
           "wifi            %s%s (%s, %d dBm), network %u, switched %u times\n"
           "\n"
           "buffered        %u windows\n"
           "last POST       %d at %s\n"
           "last upload ok  %s\n"
           "failed in a row %u\n"
           "\n"
           "/status  /log  /log/flash\n",
           s.firmware,
           (unsigned long)s.uptime_s,
           s.reset_reason,
           s.clock_set ? "set" : "NOT SET", synced,
           (long)s.clock_step_ms, (unsigned long)s.clock_step_over_s, (long)s.clock_step_max_ms,
           (unsigned long)s.heap_free, (unsigned long)s.heap_min,
           s.online ? "" : "OFFLINE, last ", s.ssid, s.ip, s.rssi, s.wifi_network, s.wifi_switches,
           s.buffered,
           s.last_post_code, lastPost,
           lastOk,
           s.upload_failures);

  server.send(200, "text/plain", body);
}

static void sendStatus() {
  refreshStatus();
  const station_status_t& s = *current;

  JsonDocument doc;
  stationStatusToJson(s, doc.to<JsonObject>());
  doc["clock_set"] = s.clock_set;
  doc["online"] = s.online;
  doc["last_post_code"] = s.last_post_code;
  doc["last_post_at"] = s.last_post_at;
  doc["last_upload_ok_at"] = s.last_upload_ok_at;

  String body;
  serializeJson(doc, body);
  server.send(200, "application/json", body);
}

static void sendRing() {
  static char body[LOG_RING_BYTES + 1];

  logRingRead(body, sizeof(body));
  server.send(200, "text/plain", body);
}

// Both files, oldest first, streamed so neither has to fit in RAM.
static void sendFlashLog() {
  const char* files[] = { LOG_FILE_PREVIOUS, LOG_FILE };

  server.setContentLength(CONTENT_LENGTH_UNKNOWN);
  server.send(200, "text/plain", "");

  for (const char* path : files) {
    File f = LittleFS.open(path, "r");
    if (!f) continue;

    uint8_t chunk[512];
    while (f.available()) {
      size_t n = f.read(chunk, sizeof(chunk));
      server.sendContent((const char*)chunk, n);
    }
    f.close();
  }

  server.sendContent("");
}

void stationHttpBegin(const station_status_t* status, void (*refresh)()) {
  current = status;
  refreshStatus = refresh;

  server.on("/", sendSummary);
  server.on("/status", sendStatus);
  server.on("/log", sendRing);
  server.on("/log/flash", sendFlashLog);
  server.onNotFound([]() { server.send(404, "text/plain", "not found\n"); });
  server.begin();
}

void stationHttpAnnounce(bool otaListening) {
  MDNS.end();

  if (!MDNS.begin(STATION_HOSTNAME)) {
    logInfo("mDNS failed, reach the station by IP");
    return;
  }

  MDNS.addService("http", "tcp", 80);
  if (otaListening) {
    MDNS.enableArduino(STATION_OTA_PORT, true);
  }
}

void stationHttpHandle() {
  server.handleClient();
}
