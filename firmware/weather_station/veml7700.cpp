#include "veml7700.h"

#include <Arduino.h>
#include <Wire.h>

#include "station_log.h"

#define VEML7700_ADDR 0x10
#define VEML7700_REG_CONF 0x00
#define VEML7700_REG_ALS 0x04

// From dusk to full sun, most sensitive first. Resolution is 0.0042 lx per
// count at gain 2 and 800 ms (Vishay's current datasheet), scaled by gain
// and integration time. Conf: gain in bits 12:11 (x1 00, x2 01, x1/8 10,
// x1/4 11), integration time in bits 9:6 (100 ms 0000, 25 ms 1100).
typedef struct {
  uint16_t conf;
  float luxPerCount;
  uint16_t integrationMs;
} veml_range_t;

static const veml_range_t RANGES[] = {
  { 0x0800, 0.0336f, 100 },  // x2, 100 ms: up to 2.2 klx
  { 0x0000, 0.0672f, 100 },  // x1, 100 ms: 4.4 klx
  { 0x1800, 0.2688f, 100 },  // x1/4, 100 ms: 17.6 klx
  { 0x1000, 0.5376f, 100 },  // x1/8, 100 ms: 35 klx
  { 0x1300, 2.1504f, 25 },   // x1/8, 25 ms: 141 klx, direct sun
};
static const uint8_t RANGE_COUNT = sizeof(RANGES) / sizeof(RANGES[0]);

// Neighbouring ranges differ by at most 4x, so a step never lands straight
// past the opposite threshold and the range does not flap.
#define VEML7700_COUNTS_SATURATED 65535
#define VEML7700_COUNTS_STEP_DOWN 50000
#define VEML7700_COUNTS_STEP_UP 1000

// Power-up plus one integration of the slowest range.
#define VEML7700_START_MS 110

// The middle one; the first read moves it where the light is.
static uint8_t range = 2;
static bool ready = false;

static bool writeConf(uint16_t conf) {
  Wire.beginTransmission(VEML7700_ADDR);
  Wire.write(VEML7700_REG_CONF);
  Wire.write(conf & 0xFF);
  Wire.write(conf >> 8);
  return Wire.endTransmission() == 0;
}

static bool readCounts(uint16_t& counts) {
  Wire.beginTransmission(VEML7700_ADDR);
  Wire.write(VEML7700_REG_ALS);
  if (Wire.endTransmission(false) != 0) {
    return false;
  }
  if (Wire.requestFrom(VEML7700_ADDR, 2) != 2) {
    return false;
  }

  counts = Wire.read();
  counts |= Wire.read() << 8;
  return true;
}

bool vemlBegin() {
  ready = writeConf(RANGES[range].conf);
  if (!ready) {
    logInfo("VEML7700 not found");
    return false;
  }

  delay(VEML7700_START_MS);
  return true;
}

// The new range applies from the next integration; the next read is a
// sample interval away, long after it finished.
static void stepRange(uint8_t next) {
  if (writeConf(RANGES[next].conf)) {
    range = next;
  } else {
    ready = false;
  }
}

bool vemlRead(uint32_t& centilux) {
  if (!ready && !vemlBegin()) {
    return false;
  }

  uint16_t counts;
  if (!readCounts(counts)) {
    logInfo("VEML7700 read failed");
    ready = false;
    return false;
  }

  const uint8_t measuredIn = range;
  if (counts > VEML7700_COUNTS_STEP_DOWN && range + 1 < RANGE_COUNT) {
    stepRange(range + 1);
  } else if (counts < VEML7700_COUNTS_STEP_UP && range > 0) {
    stepRange(range - 1);
  }

  // Past the top of the least sensitive range the true value is unknown.
  if (counts >= VEML7700_COUNTS_SATURATED) {
    return false;
  }

  centilux = (uint32_t)lroundf(counts * RANGES[measuredIn].luxPerCount * 100.0f);
  return true;
}
