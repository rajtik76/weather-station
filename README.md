# Weather forecast station

[![CI](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml/badge.svg)](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml)
[![Site](https://status.rajtik.com/api/badge/19/status?label=site)](https://status.rajtik.com)
[![Readings](https://status.rajtik.com/api/badge/18/status?label=readings)](https://status.rajtik.com)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

Can a balcony weather station forecast its own next six hours better than
assuming nothing changes? A small model, trained on eight years of Czech
Hydrometeorological Institute (ČHMÚ) records, forecasts temperature and the
chance of rain one to six hours ahead from the station's own readings alone.
Every forecast is scored against what the station then measured, and the
front page sets the last 30 days' verdict beside the next six hours. The
baseline is persistence: the temperature stays as it is.

The verdict is given for six hours ahead, with every shorter horizon beside
it, in three numbers over the last 30 days:

- **skill** - how much smaller the forecast's miss was than persistence's; below zero the forecast did worse
- **mean miss** - in °C, beside persistence's
- **in range** - how often the reading landed inside the forecast range; the range is drawn to hold 80 %, so less means too narrow

Until a day of forecasts has come true, the verdict says it is too early.

Running at [weather.rajtik.com](https://weather.rajtik.com).

## Architecture

```
SHT4x    --I2C--+
VEML7700 --I2C--+
BMP280   --I2C--+--> ESP32 --HTTPS--> Laravel API --> PostgreSQL
INMP441  --I2S--+                       |    |
                                        |  forecast service (Python)
                                   Livewire pages
```

- SHT4x (temperature, humidity) and VEML7700 (light) outside in a passive radiation shield, BMP280 (pressure) indoors, INMP441 microphone in the shield
- Readings every 30 s, folded into ten-minute windows and uploaded to the API
- Rain is detected from the microphone spectrum
- A Python service forecasts after every upload from the station's last 60 days

## Known limitations

- Sensor reads more than 10 °C above air temperature for an hour or two on clear mornings (sun on the east-facing balcony, shield only partly helps); accepted, the forecast is scored against these same readings
- VEML7700 sits behind the shield's louvers: the shape of the day is real, the lux do not compare with a station in the open
- Rain thresholds come from one rain (checked against the ČHMÚ gauge 3.5 km away) and belong to this mounting; drizzle too fine to drip is not heard

## Run it

Needs PHP 8.4, Node 24 and Docker.

```
docker compose up -d              # PostgreSQL, with a second database for the tests
composer setup                    # install, .env, app key, migrate, build assets
php artisan migrate:fresh --seed  # a month of sample data; wipes the local database
composer dev                      # server, queue worker, logs, vite
```

## Documentation

- [`docs/development.md`](docs/development.md) - layout, configuration, tests, deploying
- [`docs/api.md`](docs/api.md) - ingest endpoint and storage
- [`docs/scoring.md`](docs/scoring.md) - how the forecast is scored
- [`firmware/README.md`](firmware/README.md) - wiring, protocol, hardware notes; [`firmware/CHANGELOG.md`](firmware/CHANGELOG.md)
- [`forecast/README.md`](forecast/README.md) - model and service; [`forecast/CHANGELOG.md`](forecast/CHANGELOG.md)
- `docs/hardware/kicad/` - KiCad projects of the station and the shield hub
