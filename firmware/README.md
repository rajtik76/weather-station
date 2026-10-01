# Firmware

ESP32-WROOM-32, mains powered, never sleeps.

- Every 30 s: temperature and humidity (SHT4x), pressure (BMP280), illuminance (VEML7700)
- Continuously: INMP441 microphone
- Ten-minute windows: mean, min, max per channel plus noise levels and third-octave spectrum
- Each closed window is uploaded to `POST /api/v1/measurement` over HTTPS; failures stay buffered on flash
- Station is inspectable over the LAN without a cable

```
SHT4x    --I2C--+
VEML7700 --I2C--+
BMP280   --I2C--+--> ESP32-WROOM-32 --HTTPS--> Laravel API
INMP441  --I2S--+     core 0: noise   |    \
                      core 1: the rest |     HTTP on the LAN: status and log
                                       |
                         window buffer on the flash, 144 entries (a day)
```

## Hardware

Schematics (KiCad exports): [station](../docs/hardware/kicad/WeatherStation/WeatherStation_schematic.svg), [base board](../docs/hardware/kicad/BaseBoard/BaseBoard_schematic.svg), [shield hub](../docs/hardware/kicad/ShieldHub/ShieldHub_schematic.svg).

### Cable

4 m FTP (four twisted pairs) from ESP32 board to shield hub. ESP32 side: Dupont pins. Hub side: soldered in column 5 of the perfboard.

| Pair | Wire         | Signal | ESP32 side         | Hub side          |
| ---- | ------------ | ------ | ------------------ | ----------------- |
| 1    | blue         | SD     | GPIO33 (D33)       | R5 47 Ω → mic SD  |
| 1    | white-blue   | GND    | GND                | GND, C2           |
| 2    | orange       | SDA    | GPIO21 (D21)       | SHT4x, VEML7700   |
| 2    | white-orange | 3V3    | 3V3                | C2, all three VCC |
| 3    | white-green  | SCL    | GPIO22 (D22)       | SHT4x, VEML7700   |
| 3    | green        | GND    | GND                | GND               |
| 4    | white-brown  | SCK    | GPIO26 via R3 47 Ω | mic SCK           |
| 4    | brown        | WS     | GPIO25 via R4 47 Ω | mic WS            |

- Both grounds connect at both ends (with one open, the SHT4x on the old hub dropped about one reading in seven while WiFi was on)
- I2C lines share a pair with a supply or ground; I2S clocks have their own pair, so SCK never runs next to SDA or SCL
- Clocks still couple into I2C over 4 m, hence they stop for every sensor read (see _Noise_)
- Foil cut back at both ends; drain grounded only on the base board's cable header
- I2C pins come from the board variant via `SDA` / `SCL` (GPIO21 / GPIO22 on the DevKit); I2S pins in `noise.h`
- INMP441 `L/R` strapped to GND (left slot)
- I2C bus at 20 kHz (`I2C_CLOCK_HZ`)
- Serial: CP2102 bridge, port `/dev/cu.usbserial-*`; plugging it in resets the board

### Shield hub

[`docs/hardware/kicad/ShieldHub`](../docs/hardware/kicad/ShieldHub): [schematic](../docs/hardware/kicad/ShieldHub/ShieldHub_schematic.svg), perfboard as a board, [3D preview](../docs/hardware/kicad/ShieldHub/ShieldHub_3D_preview.png). Every KiCad file is generated; edit the script, not KiCad (edits are lost on the next run).

```
cd docs/hardware/kicad/ShieldHub
python3 generate_schematic.py        # schematic and symbols, plain Python
/Applications/KiCad/KiCad.app/Contents/Frameworks/Python.framework/Versions/3.9/bin/python3 generate_board.py
python generate_models.py            # STEP models, needs CadQuery
python generate_assembly.py          # perforated 3D assembly, needs CadQuery
```

- B.Cu tracks: tinned wire on the solder side; F.Cu tracks: insulated wires on the component side
- A wire end sits in a free hole (a via in KiCad) and is soldered from below to the run next to it; nothing is soldered on the component side
- Check changes with KiCad DRC and schematic parity

### Base board

[`docs/hardware/kicad/BaseBoard`](../docs/hardware/kicad/BaseBoard): 60 x 80 mm perfboard, DevKit and BMP280 in female headers. [Schematic](../docs/hardware/kicad/BaseBoard/BaseBoard_schematic.svg), [3D preview](../docs/hardware/kicad/BaseBoard/BaseBoard_3D_preview.png), [solder side](../docs/hardware/kicad/BaseBoard/BaseBoard_3D_solder_side.png).

- `layout.py` holds parts and runs for both generators; refuses a short or a split net
- A run is a 0 Ω link on the component side plus solder bridges between neighbouring pads below

```
cd docs/hardware/kicad/BaseBoard
python generate_models.py            # BMP280, 0 Ω links, rings and bridges; needs CadQuery
python3 generate_schematic.py        # schematic and symbols, plain Python
/Applications/KiCad/KiCad.app/Contents/Frameworks/Python.framework/Versions/3.9/bin/python3 generate_board.py
```

## Build

- Arduino IDE, board _ESP32 Dev Module_, ESP32 core 3.x
- Libraries: `Adafruit BMP280 Library`, `Adafruit SHT4x Library`, `ArduinoJson` v7; FFT is esp-dsp (in the core)
- _Partition Scheme_: _Minimal SPIFFS (1.9MB APP with OTA/128KB SPIFFS)_ - OTA needs two app slots, image ~1.2 MB; the 128 kB filesystem holds the 13 kB window buffer and two 32 kB logs
- Changing the partition scheme wipes the filesystem (buffered backlog is lost once)
- Everything else at defaults

```
cp secrets.example.h secrets.h
```

- Fill in device name, WiFi, token, OTA password; set `API_URL` in `weather_station.ino`
- `BEARER_TOKEN` must match `SENSOR_API_TOKEN` in the server's `.env`; `secrets.h` is gitignored

Two WiFi networks:

- Station lives on the primary; moves to the backup after 1 min without association or 3 consecutive failed uploads while associated
- On the backup it retries the primary every hour with an empty buffer
- Empty `BACKUP_WIFI_SSID` = single network

```
arduino-cli compile --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs weather_station
```

### Versions

- `FIRMWARE_VERSION` in `weather_station.ino` is bumped with every build that goes on a board: fix = patch, feature = minor, new board or protocol = major
- The commit is tagged `fw/v<version>` (the app uses `v<version>`)
- The board reports the version in every upload (`station_reports`)
- History in [`CHANGELOG.md`](CHANGELOG.md); field-to-server-release map in [`docs/api.md`](../docs/api.md#firmware-and-server-versions)

## Updating over the air

- Port 3232, announced over mDNS; the IDE lists `weather-station` under _Port_
- Password `OTA_PASSWORD`; empty = OTA off

```
arduino-cli upload --fqbn esp32:esp32:esp32:PartitionScheme=min_spiffs --port weather-station.local weather_station
```

- The IP works when mDNS is slow
- Image lands in the other app slot and the board restarts from it
- The window being filled is closed into the buffer first; the noise task is paused, so the window in which the update lands loses its noise
- No rollback: a boot-looping build (v2.1.0 did) stays in the slot the bootloader picks, only the cable recovers it; compile before uploading

## Looking at the station

Plain HTTP on the LAN, no authentication, read-only: `http://weather-station.local/` (mDNS) or the IP.

| Path         | What it is                                                        |
| ------------ | ----------------------------------------------------------------- |
| `/`          | What the station is doing, as text                                |
| `/status`    | The same as JSON, plus last POST's code and time                  |
| `/log`       | The last 8 kB of log from RAM, readings included                  |
| `/log/flash` | The log on the flash: everything but readings, survives a restart |

`/status` and the `station` object in every upload carry:

- Firmware version, board (`ARDUINO_BOARD`), last boot reason, uptime, free heap and its minimum
- SSID, IP, RSSI, which network, number of switches
- Windows in the buffer, uploads failed in a row
- Clock drift: `clock_step_ms` (last SNTP correction, positive when the clock ran slow), `clock_step_over_s` (how long the drift accumulated), `clock_step_max_ms` (largest since boot), `clock_synced_at` (epoch of last sync)
- Drift fields are zero until the first re-sync after boot (the first answer steps the clock from 1970 or from a clock of unknown age)

## Protocol

Version 4. Fixed-point integers, converted at reading time. One entry per ten-minute window: V2 fields, `illuminance` with extremes when the VEML7700 gave data, `noise` object when the microphone did. Units and ranges: [`docs/api.md`](../docs/api.md).

```json
{
    "sensor_name": "sensor-001",
    "protocol_version": 4,
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
            "illuminance": 1123000,
            "illuminance_min": 980000,
            "illuminance_max": 1310000,
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

- A `station` object (see above) goes beside `measurements`; stored apart from readings, optional
- Bare field = mean over the window, rounded to nearest; `_min` / `_max` = lowest / highest reading; the server refuses min above mean or max below it
- Window = epoch slot: `timestamp / 600` names it. The stamp is the last reading's.
- Pressure is station pressure (not reduced); the server reduces it to sea level for display (`App\ValueObject\SeaLevelPressure`, height `StationSite::ALTITUDE_METRES`)

## Behaviour

### Clock

- Nothing is read until NTP has answered once; the clock counts through a lost link
- SNTP re-syncs hourly while online, also after a software restart (the clock lives in the RTC)
- The sketch replaces `sntp_sync_time()` to read the clock before stepping it; the difference is the reported drift
- The replacement runs on its own task and only notes numbers; the loop writes the log line

### Buffer and upload

- Closed windows wait in a buffer, oldest first, 144 deep; in RAM, mirrored to a flash file after every change; any restart loses only the window being filled
- Batches of 16 straight after a window closes; stops at the first failure, retries a minute later
- A batch leaves the buffer only after a 2xx; the server upserts on `(sensor, timestamp)`, so a resend is harmless
- Readings outside the protocol ranges are dropped before they reach the window (one bad value would get the whole batch rejected)

### Stall guards

- Task watchdog restarts the board when the loop has not run for 2 min
- Loop restarts the board after an hour with a backlog and no successful upload, checked between windows
- Next boot logs the reset reason; the flash log rotates at 32 kB, two files deep

### Sensors

- A reading needs both SHT4x and BMP280; if either fails the reading is dropped
- SHT4x: high precision, heater off; `sht4xBegin()` spends the first read (it failed more often than not)
- BMP280: forced mode, oversampling, IIR filter off
- VEML7700: optional; a failed or saturated read drops only the light; a window without light goes out without `illuminance`
- VEML7700 is driven through its registers (`veml7700.cpp`); five ranges from gain 2 at 100 ms (0.034 lx per count) to gain 1/8 at 25 ms (141 klx); one step per read towards the light, neighbours differ by at most 4x; no non-linearity correction
- Lux are the shield's (behind the louvers), not comparable with a station in the open
- Microphone SCK and WS stop for every sensor read (`noiseHush()`); running, they made the VEML7700 miss about every other transfer; clocks also use the weakest GPIO driver

### TLS and WiFi

- `ca_certs.h` pins ISRG Root X1 and ISRG Root YR (not the leaf; Root YR survives the cross-sign being dropped); validated against the system clock
- WiFi stays associated; the loop nudges every 30 s, hands the other network a turn after a minute, and lists what the radio hears when the first association fails
- RSSI: about -70 dBm comfortable, -80 marginal, past -85 a TLS upload will not survive

## Noise

`noise.cpp`: task pinned to core 0 (below WiFi and lwIP); core 1 runs the loop.

- I2S 16 kHz, 32-bit slots, left channel; 24-bit sample read as `int32_t`, shifted `>> 8`, scaled to full scale 1 as `float`
- 2048-point FFT (esp-dsp), Hann windows overlapping by half: a frame every 64 ms, 7.8 Hz bins (4096 points left the TLS upload 2 kB of heap)
- Bin power corrected for the INMP441 high-pass (+10 dB at 25 Hz, +1.6 dB at 100 Hz, flat from 500 Hz); bins below 20 Hz left out
- 26 base-10 third-octave bands (25 Hz to 8 kHz, unweighted) and one A-weighted total (IEC 61672)
- Calibration: dB SPL = dBFS + 120 (-26 dBFS at 94 dB SPL) plus `MIC_OFFSET_DB` = -15 (phone SLM)
- Per window: `laeq` energy mean of all frames, `lamax` loudest second (LAeq,1s), `la10` / `la90` levels exceeded 10 % / 90 % of the seconds, `seconds` count; bands are energy means
- Slots follow the wall clock; nothing counted before the clock is set or for 3 s after the mic starts
- A frame with no signal (SD stuck) counts as silent and is left out: a dead mic sends windows without `noise`
- Every sensor read parks the task and disables I2S (costs about 0.5 s of noise per reading)
- The ~40 kB of buffers go back to the heap before every upload and are reallocated after (held, mbedTLS could not allocate); the window misses those seconds, so `seconds` often reads a little under 600
- A resume with no memory is retried every minute from the loop (`NOISE_RETRY_MS`)
- A mic that does not start at boot gives its buffers back; the station runs without noise until the next restart

Limits:

- Nyquist is 8 kHz: the 8 kHz band (7.1 - 8.9 kHz) sees only its lower half and reads low; the 25 - 40 Hz bands get one bin each
- Rain is detected from these bands by `App\ValueObject\RainDetector` (roof drops ring the shield's plastic at 1 kHz and fill the top band); thresholds belong to this mounting, recheck against a few rains after moving the mic or shield

## Sketches

- `weather_station` - the station
- `sensors_check` - diagnostics: I2C scan, then a line every 2 s with BMP280, SHT4x, VEML7700 and INMP441 level; clocks stopped around I2C reads as in the station; retries a missing sensor
