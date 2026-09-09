# Firmware

ESP32 firmware for the weather station. Wakes on a timer, reads a BME280,
appends the reading to a buffer in RTC memory, and uploads whatever it holds
to `POST /api/v1/measurement` over HTTPS. Anything that fails to upload stays
buffered for the next wakeup.

```
BME280 --I2C--> ESP32 --HTTPS--> Laravel API
                  |
            RTC buffer, 16 entries
```

## Hardware

DFRobot FireBeetle 2 ESP32-C6 (DFR1075) and a BME280 breakout.

The whole sensor goes to the four-pad group boxed on the silkscreen next to
the battery connector - `VIN` sits outside that box and is a supply input, not
part of it.

| BME280 | FireBeetle 2 C6 | GPIO   |
| ------ | --------------- | ------ |
| VIN    | 3V3             | -      |
| GND    | GND             | -      |
| SCL    | SCL             | GPIO20 |
| SDA    | SDA             | GPIO19 |

Those are the hardware I2C pads, not numbered D pins. The sketch takes the
numbers from the board variant through the `SDA` / `SCL` symbols, so
`BME280_SDA_PIN` / `BME280_SCL_PIN` only need editing to move the sensor
elsewhere.

`SDO` and `CSB` can stay unconnected on a breakout - it straps them, and the
firmware probes both 0x76 and 0x77.

BMP280 modules are pin compatible, frequently sold as BME280, and have no
humidity sensor. `bme280_check` reads the chip id and says which one is on the
bus.

The C6 is a RISC-V part, so this needs ESP32 core 3.x. This board carries a
LiPo connector and a charger and is built to sleep on battery, unlike the
DevKit this firmware started on, whose regulator and USB-serial chip drew
~15 mA asleep.

### The serial port comes and goes

There is no USB-serial chip here. The port is the C6's own USB peripheral, and
deep sleep powers it down, so `/dev/cu.usbmodem*` disappears entirely for the
ten minutes between wakeups and comes back for the few seconds the board is
awake. An empty serial monitor and a port that is not in the list are the
normal state of a working station, not a failed flash - the factory demo
sketch keeps the port up only because it never sleeps.

Two consequences worth knowing before debugging one of them for an hour:

- The log is printed before a monitor can reopen the port after a reset, so it
  would be lost. A cold boot waits up to `USB_ATTACH_TIMEOUT_MS` for a monitor
  to attach before it prints. A timer wakeup does not wait - nobody is
  listening on the balcony.
- Flashing needs the port to exist when `esptool` starts. Once the board is
  asleep, hold `BOOT`, tap `RST`, then release `BOOT`: the chip stays in the
  bootloader, the port stays up, and the upload has something to talk to.

### LED signal

The on-board LED on GPIO15 (`LED_PIN`, pad `D13`) blinks three times, briefly,
on two occasions only:

- when the board is powered up or reset, before anything else runs
- when the server first accepts an upload after that

Every wakeup after that is silent, and so is every fault. The LED is for the
bench: it tells you the board came up and that the link works, which is what
you stand there waiting for. Once it is on the balcony nobody is watching, and
a station that has gone quiet is what the dashboard and the heartbeat monitor
are for. The serial log says what failed.

## Build

Arduino IDE, board _DFRobot FireBeetle 2 ESP32-C6_ from ESP32 core 3.x. Needs
`Adafruit BME280 Library` and `ArduinoJson` v7.

Set _USB CDC On Boot_ to _Enabled_. It ships disabled, which points `Serial`
at UART0 on GPIO16/17 - the sketch then builds and runs, but the log goes to
pins nothing is connected to and the serial monitor stays empty.

The image fills 89% of the default 1.2 MB app partition. If a change pushes it
over, _Minimal (1.3MB APP)_ buys the room back.

```
cp secrets.example.h secrets.h
```

Fill in WiFi and the token, then set `API_URL` in `weather_station.ino`.
`BEARER_TOKEN` has to match `SENSOR_API_TOKEN` in the server's `.env`.
`secrets.h` is gitignored.

## Protocol

Version 1. Fixed point integers throughout, converted when the reading is
taken.

```json
{
    "sensor_name": "sensor-001",
    "protocol_version": 1,
    "measurements": [
        { "timestamp": 1757000000, "temperature": 2602, "humidity": 4871, "pressure": 97389 }
    ]
}
```

| Field         | Unit             | Range           |
| ------------- | ---------------- | --------------- |
| `timestamp`   | UTC Unix seconds | 1 .. 4294967295 |
| `temperature` | 0.01 °C          | -4000 .. 8500   |
| `humidity`    | 0.01 %           | 0 .. 10000      |
| `pressure`    | Pa               | 30000 .. 110000 |

Pressure is station pressure - what the sensor reads where it hangs, not
reduced. The server reduces it to sea level for display
(`App\ValueObject\SeaLevelPressure`, height in `Dashboard::ALTITUDE_METRES`),
so the record keeps the measurement and a corrected height does not mean
rewriting it. The GPS field in `bn357_types.h` is there to supply that height
once v2 carries it.

## Notes

Deep sleep is a full reboot, so buffered readings live in RTC memory. They
carry a magic number that changes with the entry layout - RTC memory holds
garbage after a power loss, and leftovers from an older firmware would
otherwise be read as valid. The buffer drops its oldest entry when full and is
cleared only after a 2xx. The server upserts on `(sensor_name, timestamp)`, so
retrying a partially delivered batch cannot duplicate rows.

Readings outside the protocol ranges are dropped before they reach the buffer.
The API validates each entry and rejects the whole batch on one bad value,
which without this would wedge every reading queued behind it.

`Adafruit_BME280::begin()` runs the sensor in normal mode at 16x oversampling
for over 100 ms before this switches it to forced, which warms the die. First
reading measured 0.10 °C high against a 0.02 °C spread once settled - a
systematic offset on every wakeup, since every wakeup is a reset. Profiled at
100 ms intervals it is within 0.02 °C after ~350 ms and within 0.01 °C after
~700 ms; `BME280_SETTLE_MS` waits 500 ms. The throwaway conversion that follows
also clears the power-on defaults sitting in the data registers, which are what
the first forced read returns until a conversion has completed.

`ca_certs.h` pins ISRG Root X1 and ISRG Root YR. Let's Encrypt renews the leaf
every few months, so pinning it would break uploads on every renewal. Two roots
because the chain is served cross-signed today and Root YR is what survives the
cross-sign being dropped. mbedTLS validates the certificate against the system
clock, so the firmware checks NTP landed before opening a connection instead of
failing on an expiry error that says nothing.

BSSID and channel are cached in RTC memory so a wakeup skips the channel scan;
the radio dominates the energy budget. Full scan is the fallback. The sensor is
read before the radio comes up, and sleep length subtracts time spent awake to
hold the 10 minute cadence.

## Sketches

`weather_station` is the station. `bme280_check` is diagnostics - I2C scan,
chip id, both addresses, live readings with range checks, and a thermal
settling profile that reports how long the sensor needs after `begin()`.
