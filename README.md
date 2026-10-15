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

**Current status:** a PHP correction model can be calibrated and evaluated
against ČHMÚ observations through Artisan. The overview and historical
charts still show original, uncorrected measurements; displaying the
estimate and its comparison page is the next stage.

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

## Temperature correction

The model estimates solar heating from light inside the shield and the
Sun's position relative to the balcony wall. It combines the current light
with exponentially weighted histories of 20 and 60 minutes to represent
heating and cooling. Non-negative, regularized regression learns the
response of this installation. The shade estimate is the measured
temperature minus the estimated heating; original records are unchanged.

Calibration and inference run in PHP, without a separate service. ČHMÚ
temperature is used for calibration and evaluation only. The running model
uses the station's light history, time and calibrated installation geometry.
The 65° wall direction is an initial estimate for this balcony and can be
overridden when calibrating another installation.

```sh
php artisan temperature:import-reference
php artisan temperature:import-reference --month=2026-09
php artisan temperature:import-reference /path/to/mikulka-20261001.json
php artisan temperature:calibrate sensor-001 --from=2026-09-30 --validate-from=2026-10-10 --until=2026-10-15
php artisan temperature:show sensor-001
```

Dates are local calendar boundaries in Europe/Prague, with exclusive ends.
Choose a start after the latest hardware or mounting change. At least
seven calibration days and three later validation days must each have
100 reference pairs; calibration also needs two sunlit mornings. Validation
reports MAE and signed error for the same raw and corrected observations,
split into sun-facing periods, daytime shade and night, plus each day.
The validation days are never used to fit coefficients. An accepted model
is available to inference only after its validation period ends.

The scheduler imports the previous and current UTC day's references every
hour. Historical monthly files and previously archived daily files can be
imported explicitly. Missing reference periods stay missing. ČHMÚ's good
and unknown-quality observations are eligible; suspect, poor, estimated,
missing and flagged observations are excluded.

The model needs an uninterrupted hour of light measurements after startup
or a gap. Unsupported readings and inputs beyond its calibrated light or
heating range return no estimate. Acceptance requires improvement overall
and on the sun-facing side, at least 90% validation coverage, and no MAE
increase above 0.05 °C at night or 0.1 °C in daytime shade.

This first model corrects solar heating. Local air differences, reflected
heat from the building, ventilation and night-time radiation are not
independently identified by the distant reference. A smaller error against
Mikulka is evidence of agreement with that station, not a measurement of
the remaining error on the balcony. Calibration over a few autumn days
does not establish accuracy in other seasons.

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
