---
paths:
    - "firmware/**"
---

# Firmware

## Re-sync the clock hourly; the oscillator drifts

SNTP must correct the clock at least once an hour (`NTP_RESYNC_AFTER_S`, handed to `esp_sntp_set_sync_interval`). Do not widen that interval: the board is powered and online, so a sync costs nothing.

Why: an earlier, sleeping firmware that synced only at cold boot let the clock free-run and it gained four minutes, so the dashboard reported the last transmission as arriving "4 minutes from now". A powered board drifts less, but the server stores what the device sends, verbatim, so any skew still lands straight in the record.

Wait on SNTP's own update, never on the clock looking plausible: on a re-sync it already does, so that test returns before the server has answered and corrects nothing. `sntp_get_sync_status()` is no better - it resets itself to RESET when read.

The sketch replaces the weak-linked `sntp_sync_time()` (declared so in `esp_sntp.h`; the Arduino core does not override it) to read the clock before stepping it - the step is the drift since the previous sync, and it rides in the station report as `clock_step_ms` / `clock_step_over_s` / `clock_step_max_ms` / `clock_synced_at`. The stock function steps first and then notifies, so the old reading is gone by the time a notification callback runs; that is why it is an override and not `sntp_set_time_sync_notification_cb`, which the override also makes redundant (it sets `ntpAnswered` itself). It runs on lwIP's task, so it only notes the numbers and `reportClockStep()` logs from `loop()` - the same rule as the WiFi events. The first answer after boot is not a drift and is skipped on `status.clock_synced_at == 0`, never on the clock being unset: after a software restart the clock comes through the RTC already set, and the interval since it was last corrected is unknown.

## SNTP starts whenever the station is online, never gated on the clock

`CONFIG_ESP_TIME_FUNCS_USE_RTC_TIMER` is on for the C3: the wall clock survives `ESP.restart()`, a watchdog panic and the OTA restart. `stationClockIsSet()` is therefore true from the first loop after any soft reset, and a `configTzTime()` reached only through the "clock unset" branch never runs on such a boot - no hourly re-sync, no drift, unbounded skew until a power cycle. `sntpBegin()` is called from `loop()` on every pass while online (idempotent); `syncClock()` only waits, and only while the clock is unset.

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

## The board never sleeps; the buffer is on the flash

Mains powered over USB. No deep sleep, no `RTC_DATA_ATTR`. `window_buffer.cpp` keeps the windows in a static array and mirrors it to `/windows.bin` on LittleFS after every add and drop - written whole into a scratch file that is renamed over the old one, so a power cut mid-write leaves the previous copy. A restart of any kind loses only the window being filled. Do not bring deep sleep back for a battery: the sampling rate that makes V2 worth having is the opposite of a sleeping design.

The upload buffer holds a day of windows and goes out sixteen to a POST (`TRANSMISSION_MAX_ENTRIES`, bounded by the 8 kB payload buffer); a longer backlog is several POSTs in a row, oldest first, stopping at the first failure. A failure is retried after `UPLOAD_RETRY_MS`, not left for the next window.

## Partition scheme is Minimal SPIFFS; the sketch does not fit the default, and OTA needs two slots

With LittleFS, WebServer, mDNS and ArduinoOTA the image is ~1.3 MB, past the 1.2 MB app slot of the default scheme. Build with `PartitionScheme=min_spiffs` (two 1.9 MB app slots, 128 kB FS) - the IDE menu entry _Minimal SPIFFS (1.9MB APP with OTA/128KB SPIFFS)_. The FS holds the 4 kB window buffer and two 32 kB logs; do not grow the logs past what fits. v2.1.x ran on `no_ota` (2 MB app, 2 MB FS) before OTA came in v2.2.0; a scheme change wipes the FS. Verify a build from the terminal with the IDE's bundled CLI: `"/Applications/Arduino IDE.app/Contents/Resources/app/lib/backend/resources/arduino-cli" compile --fqbn esp32:esp32:esp32c3:PartitionScheme=min_spiffs firmware/weather_station`.

## OTA goes over the LAN, with a password, and mDNS belongs to the HTTP module

`otaBegin()` runs at the end of `setup()` after `stationHttpBegin()` - it binds a UDP port and needs the stack, same trap as the WebServer. It is off without `OTA_PASSWORD` in `secrets.h`: an open port takes any image from anyone on the LAN. `ArduinoOTA.setMdnsEnabled(false)`, because `stationHttpAnnounce()` restarts mDNS on every association and would drop the OTA service; it advertises the OTA port itself with `MDNS.enableArduino(STATION_OTA_PORT, true)`, and only when `otaBegin()` actually started listening - an advertised port with nothing behind it shows up in the IDE and times out without a hint. The upload blocks the loop, so `onProgress` feeds the task watchdog - a transfer that stalls for two minutes still trips it, and the board comes back on the old image. `onStart` closes the open window into the buffer, so the update drops no readings. There is no rollback: a boot-looping build stays until the cable replaces it. The board has a static IP on the primary network, 192.168.0.200, which does when mDNS is slow.

## The HTTP server starts after wifiBegin(), never before

`WebServer::begin()` opens a socket, and lwIP's TCP/IP task only exists once `WiFi.mode()` has run. Called earlier it asserts inside `xQueueSemaphoreTake` on a NULL lock and the board boot-loops - v2.1.0 shipped that way. `stationHttpBegin()` stays at the end of `setup()`, after `wifiBegin()`; it does not need an association, only the stack.

## Log through station_log, never Serial directly

`logInfo()` goes to serial, the RAM ring the HTTP server hands out at `/log`, and the flash file at `/log/flash`; `logTrace()` skips the flash. A per-sample line is trace - one every half minute would wear the flash for nothing. Anything a post-mortem needs (window closed, POST result, WiFi up/down/switch, restart and why) is info. A bare `Serial.print` is invisible to everyone without the cable, and the cable resets the board - that is the whole point of the log module.

Never log from a WiFi event callback. `WiFi.onEvent()` handlers run on the core's event task - a 4 kB stack that `emit()` plus a LittleFS write would blow, and no lock on the ring - so `onWifiEvent()` only sets flags and `reportWifiEvents()` writes the lines from `loop()`. It logs a disconnect reason once per outage, not per retry: with the AP gone the core reconnects every two seconds and each try is another `NO_AP_FOUND`.

## Two networks, switched on failed uploads, not on association

`WIFI_NETWORKS[]` is primary then backup. The failover triggers on a minute without association or on `UPLOAD_FAILURES_BEFORE_SWITCH` POSTs failing in a row while associated - `WL_CONNECTED` says nothing about the uplink behind the router, and the 2026-09-17 stall (associated, silent for an hour, fixed by a power cycle) is the case this is for. `wifiConnectTo()` resets the kick timer so the nudge in `ensureWifi()` does not tear down an association still forming; keep it that way.

## Two guards restart the board; both rely on the flash buffer

Task watchdog (`WATCHDOG_TIMEOUT_MS`, subscribed in `watchdogBegin()`, fed at the top of the loop and after every batch) catches a loop that stopped running. `restartIfStuck()` catches a loop that runs but gets nowhere: an hour with a backlog and no 2xx. It is called right after `closeWindow()` and before the next `windowBegin()`, so the restart drops one reading, not a window. The next boot logs `esp_reset_reason()`, which is how a watchdog reset is told from a power cut afterwards.
