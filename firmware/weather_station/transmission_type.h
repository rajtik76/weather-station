#ifndef TRANSMISSION_TYPES_T
#define TRANSMISSION_TYPES_T

#include <stdint.h>

#include "bme280_types.h"

// Payload format carried in the "protocol_version" field. Bump it whenever
// the JSON shape changes - the server keeps every version it ever accepted,
// so the old rows stay readable.
#define TRANSMISSION_VERSION 2

// Max entries in one POST. The server takes up to 500, but a batch this
// size keeps the JSON under the payload buffer; a longer backlog goes out
// as several POSTs in a row.
#define TRANSMISSION_MAX_ENTRIES 16
#define TRANSMISSION_DEVICE_LEN  24

typedef struct {
  uint8_t version;
  char device[TRANSMISSION_DEVICE_LEN];  // e.g. "sensor-001"

  uint8_t data_count;  // valid entries in data[]
  bme280_window_t data[TRANSMISSION_MAX_ENTRIES];
} transmission_t;

#endif
