# Firmware

ESP32 firmware for the weather station. Reads temperature and humidity from
an SHT41 and pressure from a BMP280 every thirty seconds, listens to an
INMP441 microphone all the time, folds both into ten-minute windows - mean,
minimum and maximum per channel, and the noise levels and third-octave
spectrum over the same ten minutes - and uploads each closed window to
`POST /api/v1/measurement` over HTTPS. Anything that fails to upload stays
buffered on the flash until the link is back, and the station can be looked
at over the LAN without a cable.

```
SHT41   --I2C--+
BMP280  --I2C--+--> ESP32-WROOM-32 --HTTPS--> Laravel API
INMP441 --I2S--+     core 0: noise   |    \
                     core 1: the rest |     HTTP on the LAN: status and log
                                      |
                        window buffer on the flash, 144 entries (a day)
```

The board is mains powered and never sleeps. The earlier design - a battery
board waking every ten minutes for one reading - is what protocol V1
recorded; see the git history before this file for it.

## Hardware

Schematics and the soldering layout of the shield hub are in `docs/hardware/`.

The sketch takes the I2C pins from the board variant through the `SDA` /
`SCL` symbols (GPIO21 / GPIO22 on the DevKit); the I2S pins are in `noise.h`.
The INMP441's `L/R` is strapped to GND, so it talks in the left slot. The bus
runs at 20 kHz (`I2C_CLOCK_HZ`) for the margin on four metres of cable.

### Serial

The DevKit routes `Serial` through a CP2102 USB-UART bridge, so the port is
`/dev/cu.usbserial-*`. Plugging it in resets the board.

## Build

Arduino IDE, board _ESP32 Dev Module_ from ESP32 core 3.x. Needs
`Adafruit BMP280 Library`, `Adafruit SHT4x Library` and `ArduinoJson` v7;
the FFT is esp-dsp, which the core already carries. Set _Partition Scheme_
to _Minimal SPIFFS (1.9MB APP with OTA/128KB SPIFFS)_: OTA needs two app
slots, and the image is ~1.2 MB. The 128 kB of filesystem hold the 13 kB
window buffer and two 32 kB logs. The rest stays at the defaults.

Changing the partition scheme wipes the filesystem, so a backlog buffered
on the flash does not survive the switch. It is a one-time cost.

```
cp secrets.example.h secrets.h
```

Fill in the device name, WiFi, the token and an OTA password, then set
`API_URL` in `weather_station.ino`. `BEARER_TOKEN` has to match
`SENSOR_API_TOKEN` in the server's `.env`. `secrets.h` is gitignored.

Two networks can be given. The station lives on the primary and moves to
the backup when the primary will not associate for a minute, or when
three uploads in a row fail while it is associated - a link that is up
with nothing behind it looks the same as no link from the server's side,
and a second provider is what a backup is for. Once on the backup it tries
the primary again every hour, with an empty buffer, so the try costs no
data. Leave `BACKUP_WIFI_SSID` empty to run on one network.

To build from the terminal, `arduino-cli` (Homebrew, or the one bundled
with the IDE) shares the IDE's cores and libraries:

```
arduino-cli compile --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs weather_station
```

### Versions

`FIRMWARE_VERSION` in `weather_station.ino` is bumped with every build that
goes on a board - a fix is a patch, a feature a minor, a new board or
protocol a major - and the commit it was built from is tagged
`fw/v<version>`, apart from the app's own `v<version>` tags. The board
reports the number with every upload, so the dashboard and the
`station_reports` table say what is running; the tag says what that is.
[`CHANGELOG.md`](CHANGELOG.md) keeps the history with the board, protocol
and the server release each build needs; which server release understands
which field is in [`docs/api.md`](../docs/api.md#firmware-and-server-versions).

## Updating over the air

Once a build with OTA runs on the board, the next one goes over the LAN:
the board listens on port 3232, announces itself over mDNS, and the IDE
lists `weather-station` with its address under _Port_ next to the serial
ones. From the terminal:

```
arduino-cli upload --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs --port weather-station.local weather_station
```

The IP does instead of the name when mDNS is slow to answer. The image
lands in the other app slot and the board restarts from it; the window
being filled is closed into the buffer first, so the update costs no
readings, and the noise task is paused so the transfer has the CPU. The
noise of the slot the update lands in is lost - that window goes out
without it. The board asks for `OTA_PASSWORD` - an open OTA port would take
any image from anyone on the network - and with the password left empty
OTA is off altogether.

There is no rollback. A build that boot-loops, as v2.1.0 did, stays in
the slot the bootloader picks, and only the cable gets it out. Compile
before uploading, and keep the cable for that one case.

## Looking at the station

Plugging in the serial cable resets the board, which is the one thing a
look should not do. So the station serves itself over plain HTTP on the
LAN, as `http://weather-station.local/` through mDNS on whichever network
it is on, or by the IP the router hands it.

| Path         | What it is                                                        |
| ------------ | ----------------------------------------------------------------- |
| `/`          | What the station is doing, as text                                |
| `/status`    | The same as JSON                                                  |
| `/log`       | The last 8 kB of log from RAM, readings included                  |
| `/log/flash` | The log on the flash: everything but readings, survives a restart |

`/status` and the `station` object in every upload carry the same things:
firmware version and board (`ARDUINO_BOARD`, the IDE's board selection, so
the record shows which hardware sent what after a swap), why the board
last booted, uptime, free heap and the
lowest it has been, SSID, IP and RSSI, which network the station is on and
how many times it switched, how many windows wait in the buffer, how many
uploads failed in a row, and the clock's drift - `clock_step_ms`, the
correction the last SNTP re-sync made (positive when the board's clock ran
slow), `clock_step_over_s`, how long that drift accumulated, the largest
correction since boot as `clock_step_max_ms`, and `clock_synced_at`, the
epoch of the last sync. The drift fields are zero until the first re-sync
after boot: the first answer steps the clock from 1970 on a cold boot, or
from a clock nobody knows the age of after a software restart, and neither
says anything about the crystal. `/status` adds the last POST's code and time.

There is no authentication. It only reads, and it is only on the LAN.

## Protocol

Version 3. Fixed point integers throughout, converted when the reading is
taken. One entry per ten-minute window: the V2 fields, plus a `noise` object
when the microphone gave anything for that window.

```json
{
    "sensor_name": "sensor-001",
    "protocol_version": 3,
    "measurements": [
        {
            "timestamp": 1757000000,
            "temperature": 2602,
            "temperature_min": 2588,
            "temperature_max": 2631,
            "humidity": 4871,
            "humidity_min": 4820,
            "humidity_max": 4910,
            "pressure": 97389,
            "pressure_min": 97381,
            "pressure_max": 97396,
            "samples": 20,
            "noise": {
                "seconds": 600,
                "laeq": 5562,
                "lamax": 5898,
                "la10": 5797,
                "la90": 5284,
                "bands": [3120, 3305, ...26 in all...]
            }
        }
    ]
}
```

Units, ranges and what the server answers are in
[`docs/api.md`](../docs/api.md).

Beside `measurements` goes a `station` object with the state of the board
at the time of the upload - see _Looking at the station_ for the fields.
The server stores it apart from the readings, and it is optional: a batch
without it is still a valid batch.

The bare field is the mean over the window, rounded to nearest; `_min` and
`_max` are the lowest and highest reading in it. The server refuses a
minimum above its mean or a maximum below it. The mean keeps the V1 field
name on purpose - the dashboard averages both versions with one SQL
expression, and a V1 row stands in as its own minimum and maximum.

A window is an epoch slot: `timestamp / 600` names it, so the station's
windows line up with the dashboard's ten-minute buckets whatever time the
board booted. The stamp is the last reading's, which keeps the "last
measurement" readout honest and lands the entry in its own bucket.

Pressure is station pressure - what the sensor reads where it hangs, not
reduced. The server reduces it to sea level for display
(`App\ValueObject\SeaLevelPressure`, height in `Dashboard::ALTITUDE_METRES`),
so the record keeps the measurement and a corrected height does not mean
rewriting it.

## Notes

Nothing is read until NTP has answered once: a reading without a stamp
cannot be filed into a window. After that the clock keeps counting through a
lost link, and SNTP corrects it every hour while the link is up. SNTP is
started whenever the station is online, not only while the clock is unset:
the clock lives in the RTC and comes through a software restart - the
watchdog's, an OTA update's - already set, and a start gated on it would
never happen on such a boot. The sync is
waited on through SNTP's own update function, `sntp_sync_time()`, which the
sketch replaces - not by watching the clock look plausible, which on a
re-sync it already does. The replacement reads the clock before stepping
it, and the difference is the drift the crystal accumulated since the
previous sync; it goes out with the station report and shows on the
dashboard. Like the WiFi event handler it runs on a task of its own and
only notes the numbers - the loop writes the log line.

Closed windows wait in a buffer, oldest first, a day of them. The buffer is
in RAM and mirrored to a file on the flash after every change, written
whole into a scratch file that replaces the old one, so a restart of any
kind - a power cut, the watchdog, a cable plugged in - loses only the window
being filled at that moment. The upload goes out in batches of sixteen
straight after a window closes, and stops at the first failure; a failure
is retried a minute later rather than with the next window. A batch is
dropped from the buffer only after a 2xx, and the server upserts on
`(sensor, timestamp)`, so a batch whose answer got lost is harmless to send
twice.

Two things guard against a stall. The task watchdog restarts the board
when the loop has not run for two minutes - stuck in I2C or TLS - and the
loop itself restarts the board when an hour passes with a backlog and no
upload that worked, checked between windows so nothing half measured is
lost. Both are cheap with the buffer on the flash, and the next boot logs
the reset reason, so the flash log says afterwards what the station was
doing when it stopped. The log itself rotates at 32 kB, two files deep.

Readings outside the protocol ranges are dropped before they reach the
window. The API validates each entry and rejects the whole batch on one bad
value, and one wild reading would otherwise carry a window's extreme out of
range and wedge every window queued behind it.

A reading needs both sensors: the SHT41 for temperature and humidity, the
BMP280 for pressure. If either fails the reading is dropped and the window
takes the next one. The SHT41 measures at high precision without its heater;
the first read after `begin()` failed more often than not on the bench, so
`sht41Begin()` spends it. The BMP280 runs in forced mode with oversampling
and the IIR filter off: the window mean does that job, over readings half a
minute apart rather than milliseconds.

`ca_certs.h` pins ISRG Root X1 and ISRG Root YR. Let's Encrypt renews the leaf
every few months, so pinning it would break uploads on every renewal. Two roots
because the chain is served cross-signed today and Root YR is what survives the
cross-sign being dropped. mbedTLS validates the certificate against the system
clock, which is set before anything is read.

WiFi stays associated. The core reconnects by itself after a drop; the loop
nudges it every thirty seconds if that gets nowhere, hands the other network
a turn after a minute of that, and lists what the radio can hear when the
first association after boot fails - around -70 dBm is comfortable, -80
marginal, past -85 a TLS upload will not survive.

## Noise

`noise.cpp` runs as a task of its own pinned to core 0, below the WiFi
driver and lwIP, so the radio always wins; core 1 keeps the loop - sensors,
the HTTP server, the TLS upload, OTA - which would otherwise take the CPU
from the FFT for seconds at a time. The task sleeps in the I2S read and
wakes for a few milliseconds per frame.

- I2S at 16 kHz, 32-bit slots, left channel. The INMP441 sends a signed
  24-bit sample left-aligned in the slot: it is read into `int32_t` and
  shifted `>> 8` arithmetically, then scaled to full scale 1 as `float`.
  Everything after that is single precision, on the FPU.
- 2048-point FFT (esp-dsp) over Hann windows that overlap by half: a frame
  every 64 ms, 7.8 Hz bins. 4096 points left the TLS upload 2 kB of heap.
- The power in each bin is corrected for the INMP441's own high-pass - the
  biquad equalizer from esp32-i2s-slm, evaluated per bin once at boot, +10 dB
  at 25 Hz, +1.6 dB at 100 Hz, flat from 500 Hz - and summed into 26
  base-10 third-octave bands (25 Hz to 8 kHz, unweighted) and into one
  A-weighted total (IEC 61672 per bin). Bins below 20 Hz are the mic's DC
  offset and are left out.
- Calibration: dB SPL = dBFS + 120 from the datasheet (-26 dBFS at 94 dB
  SPL), plus `MIC_OFFSET_DB` = -15, matched against a phone SLM next to the
  module.
- The A-weighted power of the frames in each wall-clock second makes that
  second's LAeq,1s. Over a window slot, `laeq` is the energy mean of all
  frames, `lamax` the loudest second, `la10` and `la90` the levels 10 % and
  90 % of the seconds exceed, `seconds` how many went in; the bands are
  energy means like `laeq`.

The task follows the wall clock, so its slots are the readings' slots; it
closes one on its first frame past the boundary and the loop picks it up
when it closes the window. Nothing is counted before the clock is set, nor
for three seconds after the mic starts. A frame with no signal at all - SD
stuck low or high - is counted as silent and left out, so a dead
microphone sends windows without `noise` rather than windows of silence;
the window's log line says which.

The task's ~40 kB of buffers are what TLS needs. Before every upload the
task finishes its frame and parks, the buffers go back to the heap, and
after the upload they are allocated again - with them held, mbedTLS could
not allocate and the upload failed or hung until the watchdog. The window
being filled misses those seconds, which is why `seconds` often reads a
little under 600. A resume that finds no memory leaves the task parked and
is retried every minute from the loop (`NOISE_RETRY_MS`), not only at the
next upload. A microphone that does not start at boot - no memory, no I2S,
no task - gives its buffers back at once, and the station runs without
noise until the next restart.

Nyquist is 8 kHz, so the 8 kHz band (7.1 - 8.9 kHz) sees only its lower
half and reads low; the 25 - 40 Hz bands get one bin each.

The server hears rain in these bands (`App\ValueObject\RainDetector`): drops
off the roof ring the shield's plastic at 1 kHz and fill the top band. Those
thresholds are this mounting's. A move of the microphone or the shield
means checking them against a few rains again.

## Sketches

`weather_station` is the station. `sensors_check` is diagnostics for the
whole set: I2C scan, then one line every two seconds with the BMP280, the
SHT41 and the INMP441's level, retrying a sensor that is missing so a fixed
joint shows up without a reset.
