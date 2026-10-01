#ifndef WINDOW_BUFFER_H
#define WINDOW_BUFFER_H

#include <stdint.h>

#include "transmission_type.h"

// A day of windows; past that the oldest goes.
#define WINDOW_BUFFER_CAPACITY 144

#define WINDOW_BUFFER_FILE "/windows.bin"

// Call once, after stationFsBegin(). Returns how many windows were restored.
uint16_t windowBufferLoad();

// False if the oldest was dropped.
bool windowBufferAdd(const station_window_t& window);

uint16_t windowBufferCount();

// The oldest windows, at most TRANSMISSION_MAX_ENTRIES, oldest first.
void windowBufferToTransmission(transmission_t& tx, const char* device);

// Only after the server confirmed.
void windowBufferDrop(uint8_t count);

#endif
