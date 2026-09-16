#include "window_buffer.h"

#include <string.h>

static bme280_window_t entries[WINDOW_BUFFER_CAPACITY];
static uint16_t count = 0;

bool windowBufferAdd(const bme280_window_t& window) {
  bool dropped = false;

  if (count >= WINDOW_BUFFER_CAPACITY) {
    windowBufferDrop(1);
    dropped = true;
  }

  entries[count] = window;
  count++;

  return !dropped;
}

uint16_t windowBufferCount() {
  return count;
}

void windowBufferToTransmission(transmission_t& tx, const char* device) {
  tx.version = TRANSMISSION_VERSION;
  strncpy(tx.device, device, sizeof(tx.device) - 1);
  tx.device[sizeof(tx.device) - 1] = '\0';

  tx.data_count = count < TRANSMISSION_MAX_ENTRIES ? (uint8_t)count : TRANSMISSION_MAX_ENTRIES;
  memcpy(tx.data, entries, sizeof(bme280_window_t) * tx.data_count);
}

void windowBufferDrop(uint8_t n) {
  if (n >= count) {
    count = 0;
    return;
  }

  memmove(&entries[0], &entries[n], sizeof(bme280_window_t) * (count - n));
  count -= n;
}
