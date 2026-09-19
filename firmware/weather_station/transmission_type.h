#ifndef TRANSMISSION_TYPES_T
#define TRANSMISSION_TYPES_T

#include <stdint.h>

#include "bme280_types.h"

// Bump whenever the JSON shape changes; the server keeps every version.
#define TRANSMISSION_VERSION 2

// The server takes up to 500; this keeps the JSON under the payload buffer.
#define TRANSMISSION_MAX_ENTRIES 16
#define TRANSMISSION_DEVICE_LEN  24

typedef struct {
  uint8_t version;
  char device[TRANSMISSION_DEVICE_LEN];  // e.g. "sensor-001"

  uint8_t data_count;  // valid entries in data[]
  bme280_window_t data[TRANSMISSION_MAX_ENTRIES];
} transmission_t;

#endif
