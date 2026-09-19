#ifndef STATION_LOG_H
#define STATION_LOG_H

#include <stddef.h>
#include <stdint.h>

// A line goes to serial, to a RAM ring the HTTP server serves, and - for
// the lines worth keeping - to a file on the flash. Stamped with the wall
// clock once SNTP has set it, seconds since boot before that.

// RAM ring size; newest lines win.
#define LOG_RING_BYTES 8192

// Rotation size: the current file becomes LOG_FILE_PREVIOUS.
#define LOG_FILE "/log.txt"
#define LOG_FILE_PREVIOUS "/log.0.txt"
#define LOG_FILE_MAX_BYTES 32768

// Mounts (formatting a blank flash). Call once, first.
bool stationFsBegin();

bool stationFsMounted();

bool stationClockIsSet();

// Serial, ring and flash: window closed, upload result, WiFi, restarts.
void logInfo(const char* fmt, ...) __attribute__((format(printf, 1, 2)));

// Serial and ring only: a reading every half minute would wear the flash.
void logTrace(const char* fmt, ...) __attribute__((format(printf, 1, 2)));

// Oldest line first, NUL-terminated. Returns the length written.
size_t logRingRead(char* out, size_t outLen);

#endif
