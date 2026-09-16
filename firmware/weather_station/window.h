#ifndef WINDOW_H
#define WINDOW_H

#include <stdint.h>

#include "bme280_types.h"

// Accumulates readings over one window and closes it into the entry the
// server takes. A window is an epoch slot, WINDOW_SECONDS wide, so the
// station's windows line up with the dashboard's ten-minute buckets and a
// reboot in the middle of one only shortens that one.
#define WINDOW_SECONDS 600

typedef struct {
  uint32_t slot;          // timestamp / WINDOW_SECONDS of the readings in it
  uint32_t last;          // stamp of the newest reading
  uint16_t samples;
  int64_t t_sum;
  int64_t h_sum;
  int64_t p_sum;
  int16_t t_min, t_max;
  uint16_t h_min, h_max;
  uint32_t p_min, p_max;
} window_t;

// Which slot a stamp falls in.
uint32_t windowSlotOf(uint32_t timestamp);

// Empties the accumulator for a new slot.
void windowBegin(window_t& w, uint32_t slot);

// Folds one reading in. The reading must belong to the window's slot.
void windowAdd(window_t& w, const bme280_reading_t& reading);

// Turns the accumulator into an entry. False when the window holds nothing.
bool windowClose(const window_t& w, bme280_window_t& out);

#endif
