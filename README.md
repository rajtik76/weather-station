# Balcony Station

[![CI](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml/badge.svg)](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml)
[![Site](https://status.rajtik.com/api/badge/19/status?label=site)](https://status.rajtik.com)
[![Readings](https://status.rajtik.com/api/badge/18/status?label=readings)](https://status.rajtik.com)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

A weather station on an east-facing balcony in Plzeň, Czech Republic,
measuring temperature, humidity and pressure. Its goal is to estimate
outdoor air temperature as accurately as possible by correcting the
heating error of this particular radiation shield and balcony setup.

The SHT4x measures temperature inside a passive radiation shield. Sunlight
can heat the shield and make the sensor read above the surrounding air
temperature. The project aims to retain the original readings and show a
corrected estimate alongside them.

**Current status:** the overview and historical charts show original,
uncorrected measurements. The temperature correction and its comparison
page are the next stage of development.

Running at [weather.rajtik.com](https://weather.rajtik.com).

## Reference and evaluation

ČHMÚ Plzeň-Mikulka, approximately 3.5 km away, is the professional reference
for developing and evaluating the correction. Its historical observations
are available through [ČHMÚ open data](https://opendata.chmi.cz/meteorology/climate/).
Location, elevation and microclimate can produce real differences between
Mikulka and the balcony; agreement with Mikulka is a measurable reference,
with that limitation.

The correction will be evaluated against the uncorrected sensor on the
same observations, using days excluded from calibration. Both the mean
absolute error and the mean signed error should be compared, with sunny
periods and nights evaluated separately.

The setup changed during the record:

- Radiation shield installed on **16 September 2026 at 15:00 Europe/Prague**
- BME280 replaced by an SHT4x on 23 September 2026
- SHT4x sensor replaced and VEML7700 installed on 30 September 2026

These events are recorded in `station_events`. Calibration must account
for the shield installation and sensor changes.

## Measurements

```text
SHT4x    --I2C--+
VEML7700 --I2C--+
BMP280   --I2C--+--> ESP32 --HTTPS--> Laravel API --> PostgreSQL
INMP441  --I2S--+                                      |
                                                 Livewire pages
```

- SHT4x: temperature and humidity, outside in the radiation shield
- BMP280: pressure, on the indoor base board; its temperature is unused
- VEML7700: light inside the shield, retained as a potential correction input
- INMP441: sound in the shield, with an experimental indication of rain from its spectrum
- Samples every 30 seconds, uploaded as ten-minute windows with means and extremes
- Original measurements remain stored in the firmware's units; display conversions happen on read

Light behind the shield's louvers is specific to this mounting. Its absolute
lux values are not an open-sky radiation measurement. The microphone's rain
indication is experimental and has only been checked on a small number of
showers.

## Run it

Requires PHP 8.4, Node 24 and Docker. Docker Compose runs PostgreSQL 18;
Laravel Herd serves the local site and Vite provides the development assets.

```sh
docker compose up -d
composer setup
npm run dev
```

For a local demonstration, `php artisan migrate:fresh --seed` replaces the
local database with a month of sample measurements for two stations. The
sample weather series comes from ČHMÚ Plzeň-Mikulka, with synthetic station
variation, light and sound added for the dashboard.

ČHMÚ data is licensed CC BY 4.0. Source: Czech Hydrometeorological Institute.
The sample series uses hourly means of its ten-minute observations for
August 2025. Sample pressure is adjusted from Mikulka's elevation of 359.8 m
to the balcony's 345 m before the synthetic station variation is added.

## Documentation

- [`docs/development.md`](docs/development.md): application structure, configuration, checks and deployment
- [`docs/api.md`](docs/api.md): ingest protocol and storage
- [`firmware/README.md`](firmware/README.md): wiring, protocol and hardware notes
- [`firmware/CHANGELOG.md`](firmware/CHANGELOG.md): firmware history
- `docs/hardware/kicad/`: station, shield hub and base board KiCad projects
