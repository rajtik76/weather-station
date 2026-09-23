#ifndef TRANSMISSION_TYPES_T
#define TRANSMISSION_TYPES_T

#include <stdint.h>

#include "reading_types.h"

// Bump whenever the JSON shape changes; the server keeps every version.
#define TRANSMISSION_VERSION 3

// The server takes up to 500; this keeps the JSON under the payload buffer
// (TRANSMISSION_PAYLOAD_BYTES). An entry with noise is ~500 bytes of JSON.
#define TRANSMISSION_MAX_ENTRIES 16
#define TRANSMISSION_DEVICE_LEN  24
#define TRANSMISSION_PAYLOAD_BYTES 16384

typedef struct {
  uint8_t version;
  char device[TRANSMISSION_DEVICE_LEN];  // e.g. "sensor-001"

  uint8_t data_count;  // valid entries in data[]
  station_window_t data[TRANSMISSION_MAX_ENTRIES];
} transmission_t;

#endif
