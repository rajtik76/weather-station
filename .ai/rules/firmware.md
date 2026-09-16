---
paths:
    - "firmware/**"
---

# Firmware

## Re-sync the clock hourly; the oscillator drifts

SNTP must correct the clock at least once an hour (`NTP_RESYNC_AFTER_S`, handed to `esp_sntp_set_sync_interval`). Do not widen that interval: the board is powered and online, so a sync costs nothing.

Why: an earlier, sleeping firmware that synced only at cold boot let the clock free-run and it gained four minutes, so the dashboard reported the last transmission as arriving "4 minutes from now". A powered board drifts less, but the server stores what the device sends, verbatim, so any skew still lands straight in the record.

Wait on the SNTP notification callback (`sntp_set_time_sync_notification_cb`), never on the clock looking plausible: on a re-sync it already does, so that test returns before the server has answered and corrects nothing. `sntp_get_sync_status()` is no better - it resets itself to RESET when read.

Nothing is read until the first sync landed. A reading without a stamp cannot be filed into a window, and a wrong stamp would land it in the wrong one.

## Windows are epoch slots, stamped by their last reading

A window is `timestamp / WINDOW_SECONDS` (`windowSlotOf()`), not "ten minutes since boot", so the station's windows coincide with the dashboard's ten-minute buckets and a reboot only shortens the window it happened in. The entry's stamp is the last reading's: it lands in the same bucket and keeps the dashboard's "last measurement" readout honest. Stamping on the slot start would read ten minutes stale; stamping on the slot end would file the window into the next bucket.

The mean is rounded to nearest with a sign-aware division (`meanOf()`), because the server rejects a mean outside its own extremes and C's truncating division would put a negative mean above its maximum.

Out-of-range readings are dropped before they enter the window. One wild reading would set a window's extreme outside the protocol range, the server would refuse the batch, and every window behind it would wedge.

## ESP32-C3-DevKitM-1: the RGB LED sits on SDA, and Serial is a UART bridge

Board is Espressif ESP32-C3-DevKitM-1 (ESP32 core 3.x, RISC-V). The variant defines SDA=GPIO8 / SCL=GPIO9 and the sketch reads them through `SDA` / `SCL`, so do not hardcode numbers.

The WS2812 RGB LED (`RGB_BUILTIN`, a virtual pin number above `SOC_GPIO_PIN_COUNT`) is wired to GPIO8 as well. It decodes I2C traffic as its own data and lights up at random, usually full white. `quietSharedLed()` blanks it after every transfer - `Wire.end()`, `rgbLedWrite(RGB_BUILTIN, 0, 0, 0)`, `Wire.begin()` - because the peripheral manager hands the pin to one driver at a time; a write while I2C holds the pin goes nowhere, and an RMT write without giving it back leaves Wire pointing at a pin it no longer owns. `RGB_LED_ON_SDA 0` switches this off once the LED is cut. `digitalWrite(LED_BUILTIN)` is the same LED and the same pin - there is no plain status LED on this board.

Serial goes through the on-board CP2102N, so the port is `/dev/cu.usbserial-*`, always present, and _USB CDC On Boot_ stays _Disabled_. Enabled points `Serial` at the chip's native USB, which the board does not bring out, and the monitor is empty.

GPIO8 and GPIO9 are strapping pins. The breakout's I2C pull-ups have not upset the bootloader so far; if flashing ever fails, move the bus before blaming the cable.

## The board never sleeps; the buffer is plain RAM

Mains powered over USB. No deep sleep, no `RTC_DATA_ATTR`, no magic number to validate on wakeup - `window_buffer.cpp` is a static array that lives as long as the power does. A power cut loses the backlog of an outage and nothing else. Do not bring deep sleep back for a battery: the sampling rate that makes V2 worth having is the opposite of a sleeping design.

The upload buffer holds a day of windows and goes out sixteen to a POST (`TRANSMISSION_MAX_ENTRIES`, bounded by the 8 kB payload buffer); a longer backlog is several POSTs in a row, oldest first, stopping at the first failure.
