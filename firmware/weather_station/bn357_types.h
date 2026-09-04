#ifndef BN357_TYPES_H
#define BN357_TYPES_H

#include <stdint.h>

typedef struct {
  uint32_t timestamp;   // UTC Unix epoch, seconds
  double latitude;      // degrees
  double longitude;     // degrees
  float altitude;       // meters above sea level
} bn357_reading_t;

#endif