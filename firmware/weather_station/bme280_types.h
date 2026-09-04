#ifndef BME280_TYPES_T
#define BME280_TYPES_T

#include <stdint.h>

// Fields are stored exactly as the API takes them - fixed point integers,
// not floats. Converting once at read time keeps the RTC buffer small and
// means a buffered entry cannot drift from what was measured.
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

#endif
