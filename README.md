# Weather station

[![CI](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml/badge.svg)](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml)
[![Site](https://status.rajtik.com/api/badge/19/status?label=site)](https://status.rajtik.com)
[![Readings](https://status.rajtik.com/api/badge/18/status?label=readings)](https://status.rajtik.com)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

A personal weather station, end to end. An ESP32 reads a BME280 every half
minute, folds the readings into ten-minute windows, and uploads them to a
Laravel API that stores the readings and draws them.

```
BME280 --I2C--> ESP32 --HTTPS--> Laravel API --> PostgreSQL
                                      |
                                 Livewire dashboard
```

Running at [weather.rajtik.com](https://weather.rajtik.com).

## Accuracy

This is a hobby station, and the readings should be read as such. The sensor
sits in a passive radiation shield on an east-facing balcony, and on a clear
morning the sun hits it head on: for an hour or two the temperature then runs
more than 10 °C above the real air temperature, and the band behind the line
shows how far the samples inside a window spread. The shield went up in
September 2026 and took some of it away, not enough.

There is no fix coming. A properly ventilated site or an aspirated shield is
more than a balcony allows, and the point of the project was the pipeline
from sensor to chart, not a reference instrument. Overnight and under cloud
the numbers are as good as a BME280 gets; on a sunny morning they are not the
air temperature.

## What it does

A station is whatever uploads under a `sensor_name`. The first upload under a
new name registers it in `sensors`; a description can be added by hand
afterwards and is what the dashboard shows beside the name. More than one
station can report to the same server, and the dashboard switches between
them.

The dashboard is one Livewire page. At the top the current readings of the
chosen station, below them temperature, humidity and dew point over the last
week by default, with the min-max band behind each line and a strip of the
whole record to drag any window up to a month through; the bucket width
follows the span on screen. Events entered by hand into `station_events` are
marked on the charts. Under the charts the three newest windows as they were
stored, the station's own report of how it was doing, and the site's
approximate location - one place, set in the component, so a second station
is shown on the first one's map. The window and the station are in the URL,
so a view can be linked to.

## Layout

| Path                | Contents                                                                                                                   |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `firmware/`         | Arduino sketches. Wiring, protocol and the hardware notes worth keeping are in [`firmware/README.md`](firmware/README.md). |
| `app/Http/`         | The ingest endpoint, its form request, and the bearer token middleware.                                                    |
| `app/Enums/`        | `ProtocolVersion`, which maps a payload version to its decoder, and the bucket widths per span.                            |
| `app/ValueObject/`  | Per-version decoding of a measurement payload.                                                                             |
| `app/Models/`       | Sensors, measurements, station reports and the hand-written events.                                                        |
| `app/Livewire/`     | The dashboard component, with its view in `resources/views/livewire/`.                                                     |
| `database/seeders/` | A month of two stations' weather, for a chart without a device on the desk.                                                |
| `docs/`             | The [API contract](docs/api.md), and what happens to a batch after it lands.                                               |
| `docker/`           | nginx, PHP-FPM and supervisord config for the production image; the init script that creates the local test database.      |
| `.ai/rules/`        | Conventions that are not obvious from reading the code.                                                                    |

## API

One endpoint, bearer auth against `SENSOR_API_TOKEN`, 10 requests per minute.

```
POST /api/v1/measurement
Authorization: Bearer <token>
```

Uploads are batches. The firmware buffers what it could not deliver and sends
it with the next window, so a batch is often a retry: `(sensor, timestamp)` is
unique and the write upserts, which makes a partially delivered batch safe to
send again. One invalid entry rejects the whole batch, so a bad reading never
wedges the ones queued behind it. Beside the measurements a batch may carry a
`station` object with the board's state at the time of the upload; the server
keeps it apart from the readings.

The full contract - fields, ranges, responses - and what becomes of a batch
once it is stored are in [`docs/api.md`](docs/api.md); the firmware's side
of the payload in the [firmware README](firmware/README.md#protocol).

## Running it

Needs PHP 8.4, Node 24 and Docker. The dashboard uses the free Flux
components only, so there is nothing to buy and no `auth.json` to fill in.

```
docker compose up -d   # PostgreSQL 18 on 5432, with a second database for the tests
composer setup         # install, .env, app key, migrate, build assets
php artisan migrate:fresh --seed   # a month of sample data; wipes the local database
composer dev           # server, queue worker, logs, vite
composer test
composer review        # rector, phpstan, tests
```

Two values in `.env` are the project's own. `SENSOR_API_TOKEN` is what the
firmware sends as its bearer token; the endpoint denies everything while it
is empty.
`SENSOR_HEARTBEAT_URL` is optional: when set, the server requests it after
storing a batch, which suits a push monitor that alerts once the pings stop.

PostgreSQL everywhere, the same image as production: the dashboard averages
its buckets in SQL that only PostgreSQL speaks, so there is no SQLite to fall
back on. `MeasurementSeeder` writes a month of two stations at the reporting
interval, which is the fastest way to get something on the chart without a
device on the desk.

## Deploying

The `Dockerfile` builds one image: assets on Node, dependencies on Composer,
then nginx and PHP-FPM under supervisord on port 8080. The entrypoint caches
config, routes and views at start, because the environment only exists at run
time. It does not run migrations; do that as a step of your deploy.
