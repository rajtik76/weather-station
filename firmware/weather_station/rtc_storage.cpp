#include "rtc_storage.h"

#include <string.h>

// RTC_DATA_ATTR lives here. Unlike an .ino, a .cpp in the sketch folder
// does not get Arduino.h pulled in for it.
#include <esp_attr.h>

// Bumped whenever the layout of the buffered entries changes, so that
// leftovers from an older firmware are discarded instead of misread.
static const uint32_t RTC_BUFFER_MAGIC = 0x57535403UL;

RTC_DATA_ATTR static uint32_t rtcMagic;
RTC_DATA_ATTR static uint8_t rtcCount;
RTC_DATA_ATTR static bme280_reading_t rtcEntries[TRANSMISSION_MAX_ENTRIES];

void rtcBufferBegin() {
  if (rtcMagic != RTC_BUFFER_MAGIC || rtcCount > TRANSMISSION_MAX_ENTRIES) {
    rtcMagic = RTC_BUFFER_MAGIC;
    rtcCount = 0;
    memset(rtcEntries, 0, sizeof(rtcEntries));
  }
}

bool rtcBufferAdd(const bme280_reading_t& reading) {
  bool dropped = false;

  if (rtcCount >= TRANSMISSION_MAX_ENTRIES) {
    // Shift everything down one slot, losing the oldest entry.
    memmove(&rtcEntries[0], &rtcEntries[1],
            sizeof(bme280_reading_t) * (TRANSMISSION_MAX_ENTRIES - 1));
    rtcCount = TRANSMISSION_MAX_ENTRIES - 1;
    dropped = true;
  }

  rtcEntries[rtcCount] = reading;
  rtcCount++;

  return !dropped;
}

uint8_t rtcBufferCount() {
  return rtcCount;
}

void rtcBufferToTransmission(transmission_t& tx, const char* device) {
  tx.version = TRANSMISSION_VERSION;
  strncpy(tx.device, device, sizeof(tx.device) - 1);
  tx.device[sizeof(tx.device) - 1] = '\0';

  tx.data_count = rtcCount;
  memcpy(tx.data, rtcEntries, sizeof(bme280_reading_t) * rtcCount);
}

void rtcBufferClear() {
  rtcCount = 0;
}
