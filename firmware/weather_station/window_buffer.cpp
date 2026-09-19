#include "window_buffer.h"

#include <string.h>

#include <LittleFS.h>

#include "station_log.h"

// Header then raw entries. Bump the version whenever bme280_window_t
// changes shape, so an older file is discarded rather than read as garbage.
#define WINDOW_BUFFER_FILE_MAGIC   0x574E4457UL  // "WNDW"
#define WINDOW_BUFFER_FILE_VERSION 1

typedef struct {
  uint32_t magic;
  uint16_t version;
  uint16_t count;
} window_buffer_header_t;

static bme280_window_t entries[WINDOW_BUFFER_CAPACITY];
static uint16_t count = 0;

#define WINDOW_BUFFER_SCRATCH WINDOW_BUFFER_FILE ".tmp"

// Written whole into a scratch file that then replaces the old one, so a
// power cut mid-write leaves the previous copy. Rename over the target
// first (LittleFS does it in one step); only if the port refuses is the
// old file removed first, and windowBufferLoad() falls back to the scratch
// file for that gap.
static void persist() {
  const char* scratch = WINDOW_BUFFER_SCRATCH;

  if (!stationFsMounted()) {
    return;
  }

  File f = LittleFS.open(scratch, "w");
  if (!f) {
    logInfo("window buffer: cannot open %s for writing", scratch);
    return;
  }

  window_buffer_header_t header = {
    WINDOW_BUFFER_FILE_MAGIC,
    WINDOW_BUFFER_FILE_VERSION,
    count,
  };

  bool ok = f.write((const uint8_t*)&header, sizeof(header)) == sizeof(header)
         && f.write((const uint8_t*)entries, sizeof(bme280_window_t) * count) == sizeof(bme280_window_t) * count;
  f.close();

  if (!ok) {
    logInfo("window buffer: short write, keeping the previous file");
    LittleFS.remove(scratch);
    return;
  }

  if (!LittleFS.rename(scratch, WINDOW_BUFFER_FILE)) {
    LittleFS.remove(WINDOW_BUFFER_FILE);
    LittleFS.rename(scratch, WINDOW_BUFFER_FILE);
  }
}

// False when the file is missing or not ours.
static bool loadFrom(const char* path) {
  File f = LittleFS.open(path, "r");
  if (!f) {
    return false;
  }

  window_buffer_header_t header;
  bool ok = f.read((uint8_t*)&header, sizeof(header)) == sizeof(header)
         && header.magic == WINDOW_BUFFER_FILE_MAGIC
         && header.version == WINDOW_BUFFER_FILE_VERSION
         && header.count <= WINDOW_BUFFER_CAPACITY;

  if (ok) {
    size_t bytes = sizeof(bme280_window_t) * header.count;
    ok = f.read((uint8_t*)entries, bytes) == bytes;
  }
  f.close();

  if (!ok) {
    logInfo("window buffer: %s unreadable, dropping it", path);
    LittleFS.remove(path);
    return false;
  }

  count = header.count;
  return true;
}

uint16_t windowBufferLoad() {
  count = 0;

  if (!stationFsMounted()) {
    return 0;
  }

  // A scratch file with no real one beside it is a write that stopped after the remove.
  if (!loadFrom(WINDOW_BUFFER_FILE) && loadFrom(WINDOW_BUFFER_SCRATCH)) {
    logInfo("window buffer: recovered from the scratch file");
    persist();
  }

  LittleFS.remove(WINDOW_BUFFER_SCRATCH);
  return count;
}

bool windowBufferAdd(const bme280_window_t& window) {
  bool dropped = false;

  if (count >= WINDOW_BUFFER_CAPACITY) {
    memmove(&entries[0], &entries[1], sizeof(bme280_window_t) * (count - 1));
    count--;
    dropped = true;
  }

  entries[count] = window;
  count++;
  persist();

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
  } else {
    memmove(&entries[0], &entries[n], sizeof(bme280_window_t) * (count - n));
    count -= n;
  }

  persist();
}
