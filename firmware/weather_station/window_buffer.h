#ifndef WINDOW_BUFFER_H
#define WINDOW_BUFFER_H

#include <stdint.h>

#include "transmission_type.h"

// Closed windows awaiting confirmation, in RAM and mirrored to the flash
// after every change, so a restart loses only the window being filled.
//
// A day of windows; past that the oldest goes for the newest.
#define WINDOW_BUFFER_CAPACITY 144

#define WINDOW_BUFFER_FILE "/windows.bin"

// Call once, after stationFsBegin(). Returns how many windows came back.
uint16_t windowBufferLoad();

// False if the oldest had to go to make room.
bool windowBufferAdd(const bme280_window_t& window);

uint16_t windowBufferCount();

// The oldest windows, at most TRANSMISSION_MAX_ENTRIES, oldest first.
void windowBufferToTransmission(transmission_t& tx, const char* device);

// Call only after the server confirmed the transmission.
void windowBufferDrop(uint8_t count);

#endif
