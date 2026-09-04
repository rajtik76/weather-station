#ifndef TRANSMISSION_JSON_H
#define TRANSMISSION_JSON_H

#include <ArduinoJson.h>

#include "transmission_type.h"

// Serializes a transmission into the server's JSON payload.
// Returns the number of bytes written, excluding the terminator,
// or 0 if the buffer was too small.
static size_t transmissionToJson(const transmission_t& tx, char* out, size_t outLen) {
  JsonDocument doc;

  doc["sensor_name"] = tx.device;
  doc["protocol_version"] = tx.version;

  JsonArray measurements = doc["measurements"].to<JsonArray>();
  for (uint8_t i = 0; i < tx.data_count; i++) {
    const bme280_reading_t& r = tx.data[i];
    JsonObject entry = measurements.add<JsonObject>();

    entry["timestamp"] = r.timestamp;
    entry["temperature"] = r.temperature;
    entry["humidity"] = r.humidity;
    entry["pressure"] = r.pressure;
  }

  size_t written = serializeJson(doc, out, outLen);
  return (written > 0 && written < outLen) ? written : 0;
}

#endif
