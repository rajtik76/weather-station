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

ESP32 DevKit (WROOM) and a BME280 breakout.

| BME280 | ESP32  |
| ------ | ------ |
| VIN    | 3V3    |
| GND    | GND    |
| SDA    | GPIO21 |
| SCL    | GPIO22 |

`SDO` and `CSB` can stay unconnected on a breakout - it straps them, and the
firmware probes both 0x76 and 0x77. Pins are `BME280_SDA_PIN` /
`BME280_SCL_PIN`.

BMP280 modules are pin compatible, frequently sold as BME280, and have no
humidity sensor. `bme280_check` reads the chip id and says which one is on the
bus.

Deep sleep on this board draws ~15 mA. That is the on-board regulator and the
USB-serial chip, not the ESP32, and it rules the DevKit out for battery use.

### LED signal

The on-board LED on GPIO2 (`LED_PIN`) blinks three times, briefly, once the
server has accepted an upload - the endpoint answers 201 and the buffer is
cleared. Nothing else is signalled.

Faults are deliberately silent. A fault blink would fire on every wakeup for as
long as the fault lasted, and since the station sleeps for ten minutes between
them, seeing one means standing over the device anyway. The serial log says what
failed; the dashboard and the heartbeat monitor are what report a station that
has gone quiet.

## Build

Arduino IDE, board _ESP32 Dev Module_. Needs `Adafruit BME280 Library` and
`ArduinoJson` v7.

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

Pressure is station pressure. Reducing it to sea level for comparison against a
weather service needs the station altitude, which is what the GPS field in
`bn357_types.h` is there for.

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
