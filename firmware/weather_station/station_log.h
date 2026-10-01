#ifndef STATION_LOG_H
#define STATION_LOG_H

#include <stddef.h>
#include <stdint.h>

#define LOG_RING_BYTES 8192

// On reaching LOG_FILE_MAX_BYTES the file becomes LOG_FILE_PREVIOUS.
#define LOG_FILE "/log.txt"
#define LOG_FILE_PREVIOUS "/log.0.txt"
#define LOG_FILE_MAX_BYTES 32768

// Formats a blank flash. Call once, first.
bool stationFsBegin();

bool stationFsMounted();

bool stationClockIsSet();

// Serial, ring and flash.
void logInfo(const char* fmt, ...) __attribute__((format(printf, 1, 2)));

// Serial and ring only: spares the flash.
void logTrace(const char* fmt, ...) __attribute__((format(printf, 1, 2)));

// Oldest line first, NUL-terminated.
size_t logRingRead(char* out, size_t outLen);

#endif
