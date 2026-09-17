#ifndef TRANSMISSION_JSON_H
#define TRANSMISSION_JSON_H

#include <ArduinoJson.h>

#include "station_status.h"
#include "transmission_type.h"

// Serializes a transmission into the server's JSON payload, with the
// station's own state beside the measurements.
// Returns the number of bytes written, excluding the terminator,
// or 0 if the buffer was too small.
static size_t transmissionToJson(const transmission_t& tx, const station_status_t& status,
                                 char* out, size_t outLen) {
  JsonDocument doc;

  doc["sensor_name"] = tx.device;
  doc["protocol_version"] = tx.version;

  JsonArray measurements = doc["measurements"].to<JsonArray>();
  for (uint8_t i = 0; i < tx.data_count; i++) {
    const bme280_window_t& w = tx.data[i];
    JsonObject entry = measurements.add<JsonObject>();

    // The mean goes under the V1 name, so the server aggregates both
    // versions with one expression; the extremes take the same name with
    // a suffix.
    entry["timestamp"] = w.timestamp;
    entry["temperature"] = w.temperature;
    entry["temperature_min"] = w.temperature_min;
    entry["temperature_max"] = w.temperature_max;
    entry["humidity"] = w.humidity;
    entry["humidity_min"] = w.humidity_min;
    entry["humidity_max"] = w.humidity_max;
    entry["pressure"] = w.pressure;
    entry["pressure_min"] = w.pressure_min;
    entry["pressure_max"] = w.pressure_max;
    entry["samples"] = w.samples;
  }

  stationStatusToJson(status, doc["station"].to<JsonObject>());

  size_t written = serializeJson(doc, out, outLen);
  return (written > 0 && written < outLen) ? written : 0;
}

#endif
