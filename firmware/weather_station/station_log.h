#ifndef STATION_LOG_H
#define STATION_LOG_H

#include <stddef.h>
#include <stdint.h>

// One log line goes to three places: the serial port, a ring in RAM that
// the HTTP server hands out, and - for the lines worth keeping - a file on
// the flash that survives a restart. The serial port used to be the only
// one, and it needs a cable that resets the board when it is plugged in.
//
// Every line carries a stamp: the wall clock once SNTP has set it, seconds
// since boot before that.

// Bytes of log the RAM ring holds. The newest lines win.
#define LOG_RING_BYTES 8192

// The flash log rotates once at this size: the current file is renamed to
// LOG_FILE_PREVIOUS and a fresh one started, so the flash holds between one
// and two of these.
#define LOG_FILE "/log.txt"
#define LOG_FILE_PREVIOUS "/log.0.txt"
#define LOG_FILE_MAX_BYTES 32768

// Mounts the filesystem (formatting it on a blank flash). Call once, before
// anything else touches the flash.
bool stationFsBegin();

// Whether SNTP has set the wall clock yet - a stamp before that is junk.
bool stationClockIsSet();

// A line that matters after the fact: window closed, upload result, WiFi
// up or down, a restart and why. Serial, ring and flash.
void logInfo(const char* fmt, ...) __attribute__((format(printf, 1, 2)));

// A line that only matters right now, such as a single reading. Serial
// and ring, never the flash - one every half minute would wear it for
// nothing.
void logTrace(const char* fmt, ...) __attribute__((format(printf, 1, 2)));

// Copies the ring out, oldest line first, into a NUL-terminated buffer.
// Returns the length written.
size_t logRingRead(char* out, size_t outLen);

#endif
