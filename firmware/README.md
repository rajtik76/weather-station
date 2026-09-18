# Firmware

ESP32 firmware for the weather station. Reads a BME280 every thirty seconds,
folds the readings into ten-minute windows - mean, minimum and maximum per
channel - and uploads each closed window to `POST /api/v1/measurement` over
HTTPS. Anything that fails to upload stays buffered on the flash until the
link is back, and the station can be looked at over the LAN without a cable.

```
BME280 --I2C--> ESP32-C3 --HTTPS--> Laravel API
                  |    \
                  |     HTTP on the LAN: status and log
                  |
          window buffer on the flash, 144 entries (a day)
```

The board is mains powered over USB and never sleeps. The earlier design - a
battery board waking every ten minutes for one reading - is what protocol V1
recorded; see the git history before this file for it.

## Hardware

- ESP32-C3-DevKitM-1 v1.0, indoors, powered over USB
- BME280 breakout, on the balcony
- TFA 98.1114.0 radiation shield around it, with the breakout upright on the
  centre post of the base plate, mid-height in the plate stack, touching
  nothing
- about 4 m of outdoor FTP cable between them (Solarix FTP 4x2x0.5 CAT5E PE,
  UV resistant), carrying 3V3, GND, SDA and SCL

The shield hangs on a bracket off the top rail of an east-facing balcony,
more than half a metre from the wall and outboard of the railing, so air
reaches it from every side. Direct sun still gets through: on a clear
morning the reading runs more than 10 °C above the air around it - that is
the error of a passive shield facing the sunrise, and the V2 window band is
what shows it. The main README says what to make of it.

The breakout goes on the board's hardware I2C pins as the Arduino variant
defines them.

| BME280 | ESP32-C3-DevKitM-1 |
| ------ | ------------------ |
| VIN    | 3V3                |
| GND    | GND                |
| SCL    | GPIO9              |
| SDA    | GPIO8              |

The sketch takes the numbers from the board variant through the `SDA` /
`SCL` symbols, so `BME280_SDA_PIN` / `BME280_SCL_PIN` only need editing to
move the sensor elsewhere. Four metres of I2C is well past what the bus was
meant for, and at the default 100 kHz with the breakout's own pull-ups it
runs without a retry; if a longer run ever misbehaves, lower the clock before
anything else.

`SDO` and `CSB` can stay unconnected on a breakout - it straps them, and the
firmware probes both 0x76 and 0x77.

BMP280 modules are pin compatible, frequently sold as BME280, and have no
humidity sensor. `bme280_check` reads the chip id and says which one is on the
bus.

### The RGB LED shares a pin with SDA

The DevKitM-1 hangs its WS2812 RGB LED on GPIO8, which is also the variant's
SDA. The LED reads every I2C transfer as its own data, and whenever the line
sits low long enough to latch it lights up in whatever colour the bytes
spelled - usually full white, at random, for as long as the next transfer
takes to overwrite it.

`RGB_LED_ON_SDA` (on by default) blanks the LED after every transfer: Wire
lets go of the pin, the LED is written black, Wire takes the pin back. A
sample then shows as a flicker at most. Set it to 0 if the LED is cut off the
board, or if the sensor moves to other pins. There is no status LED - the
serial log says what the station is doing, and the dashboard and the
heartbeat monitor say when it stops.

### Serial

The DevKitM-1 routes `Serial` through a CP2102N USB-UART bridge, so the port
(`/dev/cu.usbserial-*`) is always there and _USB CDC On Boot_ stays
_Disabled_. Enabling it points `Serial` at the chip's own USB, which the
board does not bring out, and the monitor goes quiet.

## Build

Arduino IDE, board _ESP32C3 Dev Module_ from ESP32 core 3.x. Needs
`Adafruit BME280 Library` and `ArduinoJson` v7. Set _Partition Scheme_ to
_Minimal SPIFFS (1.9MB APP with OTA/128KB SPIFFS)_: the sketch is past the
1.2 MB the default scheme gives an app, and OTA needs two app slots. The
128 kB of filesystem hold the 4 kB window buffer and two 32 kB logs with
room to spare. The rest stays at the defaults - 4 MB flash, _USB CDC On
Boot_ disabled.

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

To build from the terminal, the IDE's own `arduino-cli` does it:

```
arduino-cli compile --fqbn esp32:esp32:esp32c3:PartitionScheme=min_spiffs weather_station
```

## Updating over the air

Once a build with OTA runs on the board, the next one goes over the LAN:
the board listens on port 3232, announces itself over mDNS, and the IDE
lists `weather-station at 192.168.0.200` under _Port_ next to the serial
ones. From the terminal:

```
arduino-cli upload --fqbn esp32:esp32:esp32c3:PartitionScheme=min_spiffs --port weather-station.local weather_station
```

The IP does instead of the name when mDNS is slow to answer. The image
lands in the other app slot and the board restarts from it; the window
being filled is closed into the buffer first, so the update costs no
readings. The board asks for `OTA_PASSWORD` - an open OTA port would take
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
firmware version, why the board last booted, uptime, free heap and the
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

Version 2. Fixed point integers throughout, converted when the reading is
taken. One entry per ten-minute window.

```json
{
    "sensor_name": "sensor-001",
    "protocol_version": 2,
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
            "samples": 20
        }
    ]
}
```

| Field                         | Unit             | Range           |
| ----------------------------- | ---------------- | --------------- |
| `timestamp`                   | UTC Unix seconds | 1 .. 4294967295 |
| `temperature`, `_min`, `_max` | 0.01 °C          | -4000 .. 8500   |
| `humidity`, `_min`, `_max`    | 0.01 %           | 0 .. 10000      |
| `pressure`, `_min`, `_max`    | Pa               | 30000 .. 110000 |
| `samples`                     | readings         | 1 .. 65535      |

Beside `measurements` goes a `station` object with the state of the board
at the time of the upload - see _Looking at the station_ for the fields.
The server stores it apart from the readings, and it is optional: a batch
without it is still a valid V2 batch.

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
rewriting it. The GPS field in `bn357_types.h` is there to supply that height
once a later version carries it.

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

`Adafruit_BME280::begin()` runs the sensor in normal mode at 16x oversampling
for over 100 ms before this switches it to forced, which warms the die by a
tenth of a degree; `BME280_SETTLE_MS` waits it out, once, at boot. Forced
mode keeps the sensor asleep between samples, so half a minute apart it does
not heat itself. Oversampling and the IIR filter stay off: the window mean
does that job, over readings half a minute apart rather than milliseconds.

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

## Sketches

`weather_station` is the station. `bme280_check` is diagnostics - I2C scan,
chip id, both addresses, live readings with range checks, and a thermal
settling profile that reports how long the sensor needs after `begin()`. It
does not blank the shared LED, so expect it to light up while it runs.
