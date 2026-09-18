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

![The dashboard: the last three transmissions, a week of the three channels, and the station's approximate location](docs/dashboard.png)

The screenshot is seeded sample data, not measurements - the live station has
been reporting for days rather than the month the seeded record covers.

## Accuracy

This is a hobby station, and the readings should be read as such. The sensor
sits in a passive radiation shield on an east-facing balcony, and on a clear
morning the sun hits it head on: for an hour or two the temperature then runs
more than 10 °C above the real air temperature, and the band behind the line
shows how far the samples inside a window spread. The shield went up in
September 2026 and took some of it away, not enough.

There is no fix coming. A properly ventilated site or an aspirated shield is
beyond the means and the setting the station has, and the point of the project
was the pipeline from sensor to chart, not a reference instrument. Overnight
and under cloud the numbers are as good as a BME280 gets; on a sunny morning
they are not the air temperature.

## Layout

| Path               | Contents                                                                                                                   |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| `firmware/`        | Arduino sketches. Wiring, protocol and the hardware notes worth keeping are in [`firmware/README.md`](firmware/README.md). |
| `app/Http/`        | The ingest endpoint, its form request, and the bearer token middleware.                                                    |
| `app/ValueObject/` | Per-version decoding of a measurement payload.                                                                             |
| `app/Livewire/`    | The dashboard component, with its view in `resources/views/livewire/`.                                                     |
| `.ai/rules/`       | Conventions that are not obvious from reading the code.                                                                    |

## API

One endpoint, bearer auth against `SENSOR_API_TOKEN`, 10 requests per minute.

```
POST /api/v1/measurement
Authorization: Bearer <token>
```

Uploads are batches. The firmware buffers what it could not deliver and sends
it with the next window, so a batch is often a retry: `(sensor_name, timestamp)`
is unique and the write upserts, which makes a partially delivered batch safe
to send again. One invalid entry rejects the whole batch, so a bad reading
never wedges the ones queued behind it. Payload shape, units and ranges are in
the firmware README.

Beside the measurements a batch may carry a `station` object - firmware
version, reset reason, uptime, heap, network, how many windows wait on the
board, how many uploads failed in a row and how far the clock had drifted
by its last NTP re-sync. It goes into `station_reports`,
one row per batch, and the dashboard shows the newest under the payload
tail: the board's own account of how it was doing, readable after the fact
when it has stopped answering. The board also serves the same over HTTP on
the LAN; the firmware README has the paths.

The protocol is versioned. `protocol_version` is a column of its own and never
lives inside the stored blob; `ProtocolVersion` maps a version to the value
object that validates and decodes it. A new firmware format is a new case and a
new class, and rows written by older firmware stay readable. V1 was one reading
every ten minutes; V2 is a ten-minute window of half-minute readings, sent as
the mean under the V1 keys with the extremes and the sample count beside it.
The dashboard averages both with one query - a V1 row is its own minimum and
maximum - and draws the band between the extremes behind each line.

## Running it

Needs PHP 8.4, Node 22 and Docker. Flux Pro is a paid package, so `auth.json`
has to carry credentials for `composer.fluxui.dev`.

```
docker compose up -d   # PostgreSQL 18 on 5432, with a second database for the tests
composer setup         # install, .env, app key, migrate, build assets
composer dev           # server, queue worker, logs, vite
composer test
composer review        # rector, phpstan, tests
```

PostgreSQL everywhere, the same image as production: the dashboard averages
its buckets in SQL that only PostgreSQL speaks, so there is no SQLite to fall
back on. `MeasurementSeeder` fills the dashboard's window at the reporting
interval, which is the fastest way to get something on the chart without a
device on the desk.

## Deployment

Coolify on a Hetzner VPS, nixpacks build pack, shared PostgreSQL. A push to
`main` triggers the deploy webhook, and `php artisan migrate --force` runs
after it. Commits that only touch `firmware/` are excluded from the watch
paths and do not redeploy the site.

Two Uptime Kuma monitors sit behind the badges. One polls the site. The other
is a push monitor with a one hour interval that the ingest endpoint pings after
storing a batch, so an hour of silence raises an alert - it catches a station
that stopped reporting, which polling the site never would.
