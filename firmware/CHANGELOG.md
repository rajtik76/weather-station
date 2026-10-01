# Firmware changelog

One entry per build that went on a board, tagged `fw/v<version>`. `Board` = IDE board selection, `Protocol` = `protocol_version` sent, `Server` = first app release accepting the payload (field map: [`docs/api.md`](../docs/api.md#firmware-and-server-versions)). A build reports itself as `firmware` (and `board` from 2.3) in the `station` object.

- Versions before 2.1.0 had no `FIRMWARE_VERSION`; numbers assigned afterwards from history
- 2.1.1, 2.1.2 and the OTA build of 2.2.0 shipped without a bump; boards on them report the number before
- 2.3.0 never went on a board and has no tag; its changes first shipped with 3.0.0

## 4.0.0 - 2026-09-30

Board ESP32_DEV · Protocol 4 · Server v4.0.0

- Illuminance from the VEML7700: mean, min, max per window in 0.01 lx; omitted when no light data; a failed or saturated read drops only the light
- VEML7700 steps through five ranges, 0.03 lx per count at dusk to 141 klx in full sun, one step per reading
- Parked noise task polls its flag every 100 ms (a resume racing the park left the microphone off until the next upload)
- Flash buffer format version 3; windows buffered by older builds are discarded on first boot

## 3.0.3 - 2026-09-30

Board ESP32_DEV · Protocol 3 · Server v3.0.1

- Microphone SCK and WS stop for every sensor read (coupling into SDA/SCL over 4 m made the VEML7700 miss about every other transfer); costs about 0.5 s of noise per reading
- SCK and WS on the weakest GPIO driver

## 3.0.2 - 2026-09-23

Board ESP32_DEV · Protocol 3 · Server v3.0.1

- A microphone that fails to start gives its ~40 kB buffers back at once (before, every upload ran short of heap)
- A resume that found no memory is retried every minute from the loop

## 3.0.1 - 2026-09-23

Board ESP32_DEV · Protocol 3 · Server v3.0.1

- I2C at 20 kHz instead of 100 kHz, for the 4 m cable to the SHT41
- Noise task gives its ~40 kB back for every upload (held, mbedTLS could not allocate, largest free block 36 kB, upload hung until the watchdog); a window's noise misses the seconds of its upload
- Transport errors log mbedTLS's reason and the largest free block

## 3.0.0 - 2026-09-23

Board ESP32_DEV · Protocol 3 · Server v3.0.1

- New board: ESP32-WROOM-32 (`esp32:esp32:esp32`, _ESP32 Dev Module_), still `min_spiffs`; C3's shared-LED workaround removed; first build with 2.3.0's `board` field
- New sensors: SHT41 in the radiation shield outside (`temperature`, `humidity`), BMP280 on the base board indoors (`pressure`); BME280 retired (before 3.0 all three came from it)
- Noise from an INMP441 over I2S: per window `laeq`, `lamax`, `la10`, `la90` in 0.01 dB(A) and 26 third-octave bands 25 Hz - 8 kHz, as a `noise` object (protocol 3); FFT task on core 0, loop on core 1
- Window buffer file version 2 (older buffer dropped at boot); payload buffer 16 kB

## 2.3.0 - unreleased

Board ESP32C3_DEV · Protocol 2 · Server v3.0.0

- `board` (`ARDUINO_BOARD`) in the station report and on the LAN status page
- Builds for `esp32:esp32:esp32` (ESP32-WROOM-32) as well

## 2.2.0 - 2026-09-18

Board ESP32C3_DEV · Protocol 2 · Server v2.2.0

- Clock drift per SNTP re-sync reported as `clock_step_ms`, `clock_step_over_s`, `clock_step_max_ms`, `clock_synced_at` (optional on the server as a set)
- OTA with `ArduinoOTA`: password from `secrets.h`, port advertised over mDNS
- Partition scheme `min_spiffs` for the second app slot (wiped the filesystem once)

## 2.1.2 - 2026-09-17

Board ESP32C3_DEV · Protocol 2 · Server v2.1.0 · reports itself as 2.1.0

- Logs the driver's disconnect reason once per outage
- Fails over to the backup network after a minute without association, not only after failed uploads

## 2.1.1 - 2026-09-17

Board ESP32C3_DEV · Protocol 2 · Server v2.1.0 · reports itself as 2.1.0

- HTTP server started after `wifiBegin()` (2.1.0 boot-looped on an lwIP assert)

## 2.1.0 - 2026-09-17

Board ESP32C3_DEV · Protocol 2 · Server v2.1.0

- `FIRMWARE_VERSION` and a `station` object with every batch (firmware, reset reason, uptime, heap, network, RSSI, buffered windows, failed uploads); servers before v2.1.0 ignore it
- Window buffer mirrored to LittleFS; a restart loses only the window being filled
- LAN status page (`/`, `/status`, `/log`, `/log/flash`) and mDNS
- Backup WiFi network, switched on failed uploads
- Task watchdog; restart after an hour with a backlog and no 2xx

## 2.0.0 - 2026-09-16

Board ESP32C3_DEV (ESP32-C3-DevKitM-1) · Protocol 2 · Server v2.0.0

- Mains powered over USB, no deep sleep; a reading every half minute, aggregated into ten-minute epoch windows with mean, extremes and sample count (**protocol 2**; a V1 server refuses the batch)
- RTC storage dropped; I2C on GPIO8/GPIO9 via `SDA`/`SCL` macros; on-board RGB LED shares GPIO8 and is blanked after every transfer (`RGB_LED_ON_SDA`)

## 1.6.0 - 2026-09-09

Board DFROBOT_FIREBEETLE_2_ESP32C6 · Protocol 1 · Server v1.0.0

- Full TX power, no modem sleep on the uplink

## 1.5.0 - 2026-09-09

Board DFROBOT_FIREBEETLE_2_ESP32C6 (DFRobot FireBeetle 2 ESP32-C6, DFR1075) · Protocol 1 · Server v1.0.0

- Board change from ESP32-WROOM-32: RISC-V, ESP32 core 3.x, native USB serial, LED on GPIO15; payload unchanged

## 1.4.0 - 2026-09-07

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- LED blinks at power-up and on the first delivered upload only

## 1.3.0 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Logs RSSI and the reason when an upload fails

## 1.2.0 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- LED signals a delivered upload only

## 1.1.0 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Each wakeup reported on the on-board LED

## 1.0.2 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Clock re-synced hourly

## 1.0.1 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Clock synced against the server after drifting minutes ahead

## 1.0.0 - 2026-09-04

Board ESP32_DEV (ESP32-WROOM-32) · Protocol 1 · Server v1.0.0

- First sketch: deep sleep between ten-minute wakeups, one reading per wakeup, unsent readings in RTC memory (**protocol 1**: `timestamp`, `temperature`, `humidity`, `pressure`)
