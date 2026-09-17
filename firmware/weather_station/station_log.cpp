#include "station_log.h"

#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <time.h>

#include <Arduino.h>
#include <LittleFS.h>

// Any epoch below this means the clock is not set.
static const uint32_t EPOCH_VALID_MIN = 1700000000UL;

static char ring[LOG_RING_BYTES];
static size_t ringHead = 0;   // next byte to write
static bool ringWrapped = false;

static bool fsMounted = false;

bool stationFsBegin() {
  fsMounted = LittleFS.begin(true);

  if (!fsMounted) {
    Serial.println("LittleFS mount failed, running without the flash");
  }

  return fsMounted;
}

bool stationClockIsSet() {
  return time(nullptr) >= EPOCH_VALID_MIN;
}

static size_t stampLine(char* out, size_t outLen) {
  if (stationClockIsSet()) {
    time_t now = time(nullptr);
    struct tm local;
    localtime_r(&now, &local);
    return strftime(out, outLen, "[%Y-%m-%d %H:%M:%S] ", &local);
  }

  return snprintf(out, outLen, "[+%lu.%03lus] ", millis() / 1000UL, millis() % 1000UL);
}

static void ringWrite(const char* text, size_t len) {
  for (size_t i = 0; i < len; i++) {
    ring[ringHead] = text[i];
    ringHead = (ringHead + 1) % LOG_RING_BYTES;
    if (ringHead == 0) ringWrapped = true;
  }
}

static void flashAppend(const char* text, size_t len) {
  if (!fsMounted) return;

  File f = LittleFS.open(LOG_FILE, "a");
  if (!f) return;

  f.write((const uint8_t*)text, len);
  size_t size = f.size();
  f.close();

  if (size >= LOG_FILE_MAX_BYTES) {
    LittleFS.remove(LOG_FILE_PREVIOUS);
    LittleFS.rename(LOG_FILE, LOG_FILE_PREVIOUS);
  }
}

static void emit(bool persist, const char* fmt, va_list args) {
  char line[512];

  size_t n = stampLine(line, sizeof(line));
  n += vsnprintf(line + n, sizeof(line) - n - 1, fmt, args);
  if (n > sizeof(line) - 2) n = sizeof(line) - 2;
  line[n++] = '\n';
  line[n] = '\0';

  Serial.print(line);
  ringWrite(line, n);

  if (persist) {
    flashAppend(line, n);
  }
}

void logInfo(const char* fmt, ...) {
  va_list args;
  va_start(args, fmt);
  emit(true, fmt, args);
  va_end(args);
}

void logTrace(const char* fmt, ...) {
  va_list args;
  va_start(args, fmt);
  emit(false, fmt, args);
  va_end(args);
}

size_t logRingRead(char* out, size_t outLen) {
  if (outLen == 0) return 0;

  size_t start = ringWrapped ? ringHead : 0;
  size_t available = ringWrapped ? LOG_RING_BYTES : ringHead;

  // After a wrap the oldest bytes are the tail of a line that was partly
  // overwritten; skip to the first whole one.
  if (ringWrapped) {
    while (available > 0 && ring[start] != '\n') {
      start = (start + 1) % LOG_RING_BYTES;
      available--;
    }
    if (available > 0) {
      start = (start + 1) % LOG_RING_BYTES;
      available--;
    }
  }

  size_t n = 0;
  while (available > 0 && n < outLen - 1) {
    out[n++] = ring[start];
    start = (start + 1) % LOG_RING_BYTES;
    available--;
  }
  out[n] = '\0';

  return n;
}
