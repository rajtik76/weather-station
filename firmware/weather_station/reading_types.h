#ifndef READING_TYPES_H
#define READING_TYPES_H

#include <stdint.h>

// Fixed-point integers as the API takes them, converted once at read time.
#define READING_TEMP_MIN     (-4000)  // 0.01 degC
#define READING_TEMP_MAX     (8500)
#define READING_HUMIDITY_MIN (0)      // 0.01 %
#define READING_HUMIDITY_MAX (10000)
#define READING_PRESSURE_MIN (30000)  // Pa
#define READING_PRESSURE_MAX (110000)

// Noise levels in 0.01 dB, clamped into the protocol range before they are stored.
#define NOISE_LEVEL_MIN (0)
#define NOISE_LEVEL_MAX (15000)

// Third-octave bands 25 Hz .. 8 kHz, nominal centres 10^(n/10) kHz for n = -16 .. 9.
#define NOISE_BAND_COUNT 26

// Temperature and humidity from the SHT41 outside, pressure from the BMP280 indoors.
typedef struct {
  uint32_t timestamp;    // UTC Unix epoch, seconds
  int16_t temperature;   // hundredths of a degree Celsius
  uint16_t humidity;     // hundredths of a percent
  uint32_t pressure;     // pascals
} station_reading_t;

// The microphone's account of one window. seconds == 0: no noise data, the
// entry goes out without it.
typedef struct {
  uint16_t seconds;                  // one-second levels behind the percentiles
  int16_t laeq;                      // energy mean, dB(A)
  int16_t lamax;                     // loudest one-second Leq
  int16_t la10;                      // exceeded 10 % of the seconds
  int16_t la90;                      // exceeded 90 % of the seconds
  int16_t bands[NOISE_BAND_COUNT];   // unweighted Leq per band
} noise_window_t;

// One window: the mean per channel under the single-reading name, the
// extremes beside it, the sample count, and the noise over the same slot.
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
  noise_window_t noise;
} station_window_t;

#endif
