#ifndef WINDOW_BUFFER_H
#define WINDOW_BUFFER_H

#include <stdint.h>

#include "transmission_type.h"

// Closed windows waiting for the server to confirm them. Held in RAM and
// mirrored to a file on the flash after every change, so a restart - a
// power cut, the watchdog, a cable plugged in for a look - loses nothing
// that was already closed. Only the window being filled at that moment is
// gone, and that is at most ten minutes.
//
// A day of windows. Past that the oldest is dropped for the newest, which
// keeps the freshest weather on the dashboard when the link comes back.
#define WINDOW_BUFFER_CAPACITY 144

#define WINDOW_BUFFER_FILE "/windows.bin"

// Reads the backlog left by the previous run, if any. Call once, after
// stationFsBegin(). Returns how many windows came back.
uint16_t windowBufferLoad();

// Appends one window. Returns false if the oldest had to go to make room.
bool windowBufferAdd(const bme280_window_t& window);

uint16_t windowBufferCount();

// Copies the oldest windows into a transmission, at most
// TRANSMISSION_MAX_ENTRIES of them. Oldest first, so a backlog reaches the
// server in the order it was measured.
void windowBufferToTransmission(transmission_t& tx, const char* device);

// Drops the windows the transmission carried. Call only after the server
// confirmed it - anything dropped earlier is gone for good.
void windowBufferDrop(uint8_t count);

#endif
