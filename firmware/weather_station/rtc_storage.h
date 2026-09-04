#ifndef RTC_STORAGE_H
#define RTC_STORAGE_H

#include <stdint.h>

#include "transmission_type.h"

// Validates the RTC buffer after wakeup and resets it when the contents
// are not ours - RTC memory keeps its data across deep sleep, but holds
// garbage after a power loss or a hard reset. Call once, early in setup().
void rtcBufferBegin();

// Appends one reading. When the buffer is full the oldest entry is dropped,
// so the newest measurement always makes it in. Returns false if an entry
// had to be discarded to make room.
bool rtcBufferAdd(const bme280_reading_t& reading);

uint8_t rtcBufferCount();

// Copies the buffered readings into a transmission ready for serialization.
void rtcBufferToTransmission(transmission_t& tx, const char* device);

// Call only after the server confirmed the payload. Anything still buffered
// at this point would otherwise be sent twice.
void rtcBufferClear();

#endif
