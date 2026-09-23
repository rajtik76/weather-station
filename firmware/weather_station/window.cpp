#include "window.h"

#include <string.h>

uint32_t windowSlotOf(uint32_t timestamp) {
  return timestamp / WINDOW_SECONDS;
}

void windowBegin(window_t& w, uint32_t slot) {
  w.slot = slot;
  w.last = 0;
  w.samples = 0;
  w.t_sum = w.h_sum = w.p_sum = 0;
  w.t_min = w.t_max = 0;
  w.h_min = w.h_max = 0;
  w.p_min = w.p_max = 0;
}

void windowAdd(window_t& w, const station_reading_t& r) {
  if (w.samples == 0) {
    w.t_min = w.t_max = r.temperature;
    w.h_min = w.h_max = r.humidity;
    w.p_min = w.p_max = r.pressure;
  } else {
    if (r.temperature < w.t_min) w.t_min = r.temperature;
    if (r.temperature > w.t_max) w.t_max = r.temperature;
    if (r.humidity < w.h_min) w.h_min = r.humidity;
    if (r.humidity > w.h_max) w.h_max = r.humidity;
    if (r.pressure < w.p_min) w.p_min = r.pressure;
    if (r.pressure > w.p_max) w.p_max = r.pressure;
  }

  w.t_sum += r.temperature;
  w.h_sum += r.humidity;
  w.p_sum += r.pressure;
  w.last = r.timestamp;
  w.samples++;
}

// Round to nearest so a negative mean still lands between the extremes.
static int64_t meanOf(int64_t sum, uint16_t n) {
  return sum >= 0 ? (sum + n / 2) / n : -((-sum + n / 2) / n);
}

bool windowClose(const window_t& w, station_window_t& out) {
  if (w.samples == 0) return false;

  out.timestamp = w.last;
  out.temperature = (int16_t)meanOf(w.t_sum, w.samples);
  out.temperature_min = w.t_min;
  out.temperature_max = w.t_max;
  out.humidity = (uint16_t)meanOf(w.h_sum, w.samples);
  out.humidity_min = w.h_min;
  out.humidity_max = w.h_max;
  out.pressure = (uint32_t)meanOf(w.p_sum, w.samples);
  out.pressure_min = w.p_min;
  out.pressure_max = w.p_max;
  out.samples = w.samples;

  // The microphone runs on its own clock; the caller fills this in.
  memset(&out.noise, 0, sizeof(out.noise));

  return true;
}
