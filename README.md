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
against ČHMÚ observations through Artisan. Overview and Charts display
the original temperature alongside the calculated shade estimate. The
Reference page compares the shade estimate with the shade reference and
Mikulka by day, up to 30 days back, and reports deviations over the last
30 days.

Running at [weather.rajtik.com](https://weather.rajtik.com).

## Reference and evaluation

ČHMÚ Plzeň-Mikulka, approximately 3.5 km away, is the professional reference
for developing and evaluating the correction. Its historical observations
are available through [ČHMÚ open data](https://opendata.chmi.cz/meteorology/climate/).
Location, elevation and microclimate can produce real differences between
Mikulka and the balcony; agreement with Mikulka is a measurable reference,
with that limitation.

The correction is therefore fitted and judged against a shade reference
(`ShadeReference`): Mikulka shifted by the balcony's own offset from it.
The offset is read while the shield is in shade, in the hour before the
sun reaches it (below 300 lx) and 30 to 60 minutes after the sun has left,
and runs in a straight line between the two from the moment the sun
reaches the shield until an hour after it leaves, while the shield's
heating history decays. Outside that window the shade reference is
Mikulka. A day without both anchors has no reference in that window, and
its readings there are left out of calibration and validation. On overcast mornings between 1 October
and 5 November 2026, when the right difference is zero, the shade reference
was 0.21 °C from zero on average and Mikulka 0.52 °C. The straight line
between the anchors is an assumption that overcast mornings cannot check.

The correction will be evaluated against the uncorrected sensor on the
same observations, using days excluded from calibration. Both the mean
absolute error and the mean signed error should be compared, with sunny
periods and nights evaluated separately.

The setup changed during the record:

- Radiation shield installed on **16 September 2026 at 15:00 Europe/Prague**
- BME280 replaced by an SHT4x on 23 September 2026
- SHT4x sensor replaced and VEML7700 installed on 30 September 2026

These events are recorded in `station_events` and marked on the charts.
They do not block calibration or invalidate an existing correction model.

## Temperature correction

The model estimates solar heating from light inside the shield and the
Sun's position relative to the balcony wall. Feature version 2 combines
the current light with exponentially weighted histories of 10 and 30
minutes, and scales the 10 minute history by low sun (below 20° elevation
the shield heats more for the same light) and by the microphone's 25 Hz
band (wind ventilates the shield). Regularized regression learns the
response of this installation; heating coefficients stay non-negative, the
wind term takes either sign. Version 1 (light with 20 and 60 minute histories)
is still applied for calibrations trained with it. The shade estimate is
the measured temperature minus the estimated heating; original records
are unchanged.

Calibration and inference run in PHP, without a separate service. ČHMÚ
temperature is used for calibration and evaluation only. The running model
uses the station's light and sound history, time and calibrated
installation geometry.
The wall direction is the balcony facade at 71.8° clockwise from true north;
override it when calibrating another installation.

```sh
php artisan temperature:import-reference
php artisan temperature:import-reference --days=30
php artisan temperature:import-reference --month=2026-09
php artisan temperature:import-reference /path/to/mikulka-20261001.json
php artisan temperature:calibrate sensor-001 --from=2026-09-30 --validate-from=2026-10-10 --until=2026-10-15
php artisan temperature:show sensor-001
```

Dates are local calendar boundaries in Europe/Prague, with exclusive ends.
Station events do not restrict the calibration range. At least
seven calibration days and three later validation days must each have
100 reference pairs; calibration also needs two sunlit mornings. Validation
reports MAE and signed error for the same raw and corrected observations,
split into sun-facing periods, daytime shade and night, plus each day.
The validation days are never used to fit coefficients. An accepted model
is available to inference only after its validation period ends.

The scheduler imports the previous and current UTC day's references every
hour. Run `--days=30` once to backfill history from monthly and daily
archives; the scheduled import continues to fetch only yesterday and today. Missing reference periods stay missing. ČHMÚ's good
and unknown-quality observations are eligible; suspect, poor, estimated,
missing and flagged observations are excluded.

The model needs an uninterrupted hour of light measurements after startup
or a gap. Unsupported readings and inputs beyond its calibrated light or
heating range return no estimate. Validation scores against the shade
reference. With at least two sunlit validation mornings, acceptance
requires a sun-facing MAE at least 10% below the raw sensor and a smaller
sun-facing bias; with fewer, the sun-facing MAE may not exceed the raw
sensor's by more than 0.05 °C. Always: no overall MAE increase above
0.05 °C, at least 90% validation coverage, no MAE increase above 0.05 °C at
night or 0.1 °C in daytime shade. A retrained model must also be within
0.02 °C of the model in use, overall and sun-facing, on the same pairs.

The model corrects solar heating. Local air differences, reflected heat
from the building and night-time radiation are not corrected: in afternoon
shade the balcony runs up to about a degree colder than Mikulka on sunny,
calm days and warmer on windy, overcast ones, and that is the balcony's
air, not the shield. A smaller error against the shade reference is
evidence of a better shield correction, not a measurement of the remaining
error on the balcony. Calibration over a few autumn days
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
indication is experimental: its thresholds were fitted to this mounting
against ČHMÚ Mikulka's rain gauge, and the owner's reports on Discord keep
checking it.

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
