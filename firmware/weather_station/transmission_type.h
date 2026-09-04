#ifndef TRANSMISSION_TYPES_T
#define TRANSMISSION_TYPES_T

#include <stdint.h>

#include "bme280_types.h"

// Payload format carried in the "version" field. Bump it whenever the
// JSON shape changes - v2 is expected to add a GPS object per entry.
#define TRANSMISSION_VERSION 1

// Max entries in one transmission. At a 10 minute interval this covers
// roughly 2.5 hours of failed uploads before the oldest reading is lost.
#define TRANSMISSION_MAX_ENTRIES 16
#define TRANSMISSION_DEVICE_LEN  24

typedef struct {
  uint8_t version;
  char device[TRANSMISSION_DEVICE_LEN];  // e.g. "sensor-001"

  uint8_t data_count;  // valid entries in data[]
  bme280_reading_t data[TRANSMISSION_MAX_ENTRIES];
} transmission_t;

#endif
