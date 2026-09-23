#ifndef WINDOW_H
#define WINDOW_H

#include <stdint.h>

#include "reading_types.h"

// A window is an epoch slot WINDOW_SECONDS wide, so it lines up with the
// dashboard's buckets and a reboot only shortens the one it falls in.
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

uint32_t windowSlotOf(uint32_t timestamp);

void windowBegin(window_t& w, uint32_t slot);

// The reading must belong to the window's slot.
void windowAdd(window_t& w, const station_reading_t& reading);

// False when the window holds nothing.
bool windowClose(const window_t& w, station_window_t& out);

#endif
