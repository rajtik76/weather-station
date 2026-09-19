#ifndef STATION_STATUS_H
#define STATION_STATUS_H

#include <stdint.h>

#include <ArduinoJson.h>

// Served whole on the LAN; a subset rides with every batch so a stall can
// be read back from the server afterwards.
typedef struct {
  const char* firmware;       // FIRMWARE_VERSION
  const char* reset_reason;   // why the board last booted
  uint32_t uptime_s;
  uint32_t heap_free;         // bytes
  uint32_t heap_min;          // lowest heap_free since boot
  bool clock_set;
  int32_t clock_step_ms;      // last SNTP correction: positive when the board's clock ran slow, 0 before the first re-sync
  uint32_t clock_step_over_s; // seconds between the previous sync and the one that made the step
  int32_t clock_step_max_ms;  // the correction of largest magnitude since boot, sign kept
  uint32_t clock_synced_at;   // epoch of the last SNTP correction, 0 never

  bool online;
  char ssid[33];              // the last network seen; kept while offline
  char ip[16];                // likewise
  int8_t rssi;                // dBm, 0 when offline
  uint8_t wifi_network;       // 0 primary, 1 backup
  uint16_t wifi_switches;     // how many times the station changed network

  uint16_t buffered;          // windows waiting for the server
  uint16_t upload_failures;   // POSTs failed in a row
  int last_post_code;         // HTTP status or negative transport error, 0 never
  uint32_t last_post_at;      // epoch of the last POST attempt, 0 never
  uint32_t last_upload_ok_at; // epoch of the last 2xx, 0 never
} station_status_t;

// The subset that rides with every batch.
static void stationStatusToJson(const station_status_t& s, JsonObject out) {
  out["firmware"] = s.firmware;
  out["reset_reason"] = s.reset_reason;
  out["uptime"] = s.uptime_s;
  out["heap_free"] = s.heap_free;
  out["heap_min"] = s.heap_min;
  out["ssid"] = s.ssid;
  out["ip"] = s.ip;
  out["rssi"] = s.rssi;
  out["wifi_network"] = s.wifi_network;
  out["wifi_switches"] = s.wifi_switches;
  out["buffered"] = s.buffered;
  out["upload_failures"] = s.upload_failures;
  out["clock_step_ms"] = s.clock_step_ms;
  out["clock_step_over_s"] = s.clock_step_over_s;
  out["clock_step_max_ms"] = s.clock_step_max_ms;
  out["clock_synced_at"] = s.clock_synced_at;
}

#endif
