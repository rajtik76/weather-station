# Firmware changelog

One entry per build that went on a board. Each is tagged `fw/v<version>`
on the commit it was built from and reports itself as `firmware` (and,
from 2.3, `board`) in the `station` object of every upload. `Board` is the
IDE's board selection, `Protocol` the `protocol_version` sent, `Server`
the first release of the app that accepts the payload; which release
understands which field is tabled in [`docs/api.md`](../docs/api.md#firmware-and-server-versions).

Versions before 2.1.0 carried no `FIRMWARE_VERSION`; those numbers were
assigned afterwards from the history. 2.1.1, 2.1.2 and the OTA build of
2.2.0 shipped without a bump, so a board on them reports the number
before.

## 3.0.1 - 2026-09-23

Board ESP32_DEV · Protocol 3 · Server v3.0.1

- I2C at 20 kHz instead of the default 100 kHz, for the 4 m cable to the
  SHT41.
- The noise task gives its ~40 kB back for every upload and takes it again
  after. With it held, mbedTLS could not allocate (largest free block
  36 kB): the upload failed or hung until the watchdog restarted the board,
  every two minutes. The noise of a window misses the seconds of its upload.
- A transport error logs mbedTLS's own reason and the largest free block.

## 3.0.0 - 2026-09-23

Board ESP32_DEV · Protocol 3 · Server v3.0.1

- New board: ESP32-WROOM-32 (`esp32:esp32:esp32`, _ESP32 Dev Module_),
  still `min_spiffs`. The C3's shared-LED workaround is gone.
- New sensors. `temperature` and `humidity` come from an SHT41 in the
  radiation shield outside, `pressure` from a BMP280 on the base board
  indoors. The BME280 is retired; before 3.0 all three fields came from it.
- Noise from an INMP441 in the shield, over I2S: per window `laeq`,
  `lamax`, `la10`, `la90` in 0.01 dB(A) and 26 third-octave bands 25 Hz -
  8 kHz, sent as a `noise` object beside the V2 fields (protocol 3). A task
  on core 0 does the FFT; the loop keeps core 1.
- The window buffer file changed shape (version 2); a buffer left by an
  older build is dropped at boot. Payload buffer 16 kB.

## 2.3.0 - unreleased

Board ESP32C3_DEV · Protocol 2 · Server v3.0.0

- `board` in the station report and on the LAN status page: the IDE's
  board selection (`ARDUINO_BOARD`), so the record shows which hardware
  sent what after a swap. The version is bumped to 2.3.0.
- Builds for `esp32:esp32:esp32` (ESP32-WROOM-32) as well.

## 2.2.0 - 2026-09-18

Board ESP32C3_DEV · Protocol 2 · Server v2.2.0

- Clock drift measured at each SNTP re-sync and reported as
  `clock_step_ms`, `clock_step_over_s`, `clock_step_max_ms`,
  `clock_synced_at`. Optional on the server as a set, all four or none.
- Updates over the air with `ArduinoOTA`, password from `secrets.h`, port
  advertised over mDNS. Partition scheme changed to `min_spiffs` for the
  second app slot - the change wiped the filesystem once.

## 2.1.2 - 2026-09-17

Board ESP32C3_DEV · Protocol 2 · Server v2.1.0 · reports itself as 2.1.0

- Logs the driver's disconnect reason once per outage. Fails over to the
  backup network after a minute without association, not only after
  failed uploads.

## 2.1.1 - 2026-09-17

Board ESP32C3_DEV · Protocol 2 · Server v2.1.0 · reports itself as 2.1.0

- HTTP server started after `wifiBegin()`; 2.1.0 boot-looped on an
  assert in lwIP because the socket was opened before the stack existed.

## 2.1.0 - 2026-09-17

Board ESP32C3_DEV · Protocol 2 · Server v2.1.0

- `FIRMWARE_VERSION` introduced and sent in a `station` object with every
  batch: firmware, reset reason, uptime, heap, network, RSSI, buffered
  windows, failed uploads. Server stores it from v2.1.0; earlier servers
  ignore it.
- Window buffer mirrored to LittleFS, so a restart of any kind loses only
  the window being filled.
- LAN status page (`/`, `/status`, `/log`, `/log/flash`) and mDNS.
- Backup WiFi network, switched on failed uploads.
- Task watchdog and a restart after an hour with a backlog and no 2xx.

## 2.0.0 - 2026-09-16

Board ESP32C3_DEV (ESP32-C3-DevKitM-1) · Protocol 2 · Server v2.0.0

- Board change and a new regime: mains powered over USB, no deep sleep.
  A reading every half minute, aggregated into ten-minute epoch windows
  with mean, extremes and sample count - **protocol 2**. Server decodes
  it from v2.0.0; a V1 server refuses the batch.
- RTC storage dropped with the sleep. I2C moves to the C3's GPIO8/GPIO9
  through the `SDA`/`SCL` macros; the on-board RGB LED shares GPIO8 and is
  blanked after every transfer (`RGB_LED_ON_SDA`).

## 1.6.0 - 2026-09-09

Board DFROBOT_FIREBEETLE_2_ESP32C6 · Protocol 1 · Server v1.0.0

- Full TX power and no modem sleep on the uplink.

## 1.5.0 - 2026-09-09

Board DFROBOT_FIREBEETLE_2_ESP32C6 (DFRobot FireBeetle 2 ESP32-C6, DFR1075) · Protocol 1 · Server v1.0.0

- Board change from the ESP32-WROOM-32. RISC-V, ESP32 core 3.x, native
  USB serial, LED on GPIO15. Payload unchanged.

## 1.4.0 - 2026-09-07

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- LED blinks at power-up and on the first delivered upload only.

## 1.3.0 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Logs RSSI and the reason when an upload fails.

## 1.2.0 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- LED signals a delivered upload only.

## 1.1.0 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Each wakeup reported on the on-board LED.

## 1.0.2 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Clock re-synced hourly.

## 1.0.1 - 2026-09-05

Board ESP32_DEV · Protocol 1 · Server v1.0.0

- Clock synced against the server after it had drifted minutes ahead.

## 1.0.0 - 2026-09-04

Board ESP32_DEV (ESP32-WROOM-32) · Protocol 1 · Server v1.0.0

- First sketch in the repo. Deep sleep between ten-minute wakeups, one
  reading per wakeup, unsent readings kept in RTC memory - **protocol 1**:
  `timestamp`, `temperature`, `humidity`, `pressure`.
