#ifndef STATION_STATUS_H
#define STATION_STATUS_H

#include <stdint.h>

#include <ArduinoJson.h>

// What the station knows about itself. Goes out two ways: to the HTTP
// server on the LAN, whole, and to the API alongside every batch, so the
// record shows what the board was doing when the readings were taken and
// a stall can be read back from the server after the board recovered.
typedef struct {
  const char* firmware;       // FIRMWARE_VERSION
  const char* reset_reason;   // why the board last booted
  uint32_t uptime_s;
  uint32_t heap_free;         // bytes
  uint32_t heap_min;          // lowest heap_free since boot
  bool clock_set;

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

// The subset that rides with every batch. The rest is only for the LAN.
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
}

#endif
