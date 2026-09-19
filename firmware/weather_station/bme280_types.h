#ifndef BME280_TYPES_T
#define BME280_TYPES_T

#include <stdint.h>

// Fixed-point integers as the API takes them, converted once at read time.
#define BME280_TEMP_MIN     (-4000)  // 0.01 degC
#define BME280_TEMP_MAX     (8500)
#define BME280_HUMIDITY_MIN (0)      // 0.01 %
#define BME280_HUMIDITY_MAX (10000)
#define BME280_PRESSURE_MIN (30000)  // Pa
#define BME280_PRESSURE_MAX (110000)

typedef struct {
  uint32_t timestamp;    // UTC Unix epoch, seconds
  int16_t temperature;   // hundredths of a degree Celsius
  uint16_t humidity;     // hundredths of a percent
  uint32_t pressure;     // pascals
} bme280_reading_t;

// One window: the mean per channel under the single-reading name, the
// extremes beside it, and the sample count.
typedef struct {
  uint32_t timestamp;    // stamp of the last reading in the window, UTC
  int16_t temperature;
  int16_t temperature_min;
  int16_t temperature_max;
  uint16_t humidity;
  uint16_t humidity_min;
  uint16_t humidity_max;
  uint32_t pressure;
  uint32_t pressure_min;
  uint32_t pressure_max;
  uint16_t samples;
} bme280_window_t;

#endif
