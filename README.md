# Weather station

[![CI](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml/badge.svg)](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml)
[![Site](https://status.rajtik.com/api/badge/19/status?label=site)](https://status.rajtik.com)
[![Readings](https://status.rajtik.com/api/badge/18/status?label=readings)](https://status.rajtik.com)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

A personal weather station, end to end. An ESP32 reads temperature and
humidity from an SHT41 outside and pressure from a BMP280 indoors every half
minute, listens to an INMP441 microphone beside the SHT41 all the time, folds
both into ten-minute windows, and uploads them to a Laravel API that stores
the windows and draws them. A small model trained on ČHMÚ station records
forecasts the next six hours from the station's own readings, and the
microphone's spectrum tells when it rains.

```
SHT41   --I2C--+
BMP280  --I2C--+--> ESP32 --HTTPS--> Laravel API --> PostgreSQL
INMP441 --I2S--+                       |    |
                                       |  forecast service (Python)
                                  Livewire dashboard
```

Running at [weather.rajtik.com](https://weather.rajtik.com).

## Accuracy

This is a hobby station, and the readings should be read as such. The SHT41
sits in a passive radiation shield on an east-facing balcony, and on a clear
morning the sun hits it head on: for an hour or two the temperature then runs
more than 10 °C above the real air temperature, and the band behind the line
shows how far the samples inside a window spread. The shield went up in
September 2026 and took some of it away, not enough.

There is no fix coming. A properly ventilated site or an aspirated shield is
more than a balcony allows, and the point of the project was the pipeline
from sensor to chart, not a reference instrument. Overnight and under cloud
the numbers are as good as an SHT41 gets; on a sunny morning they are not the
air temperature.

## What it does

A station is whatever uploads under a `sensor_name`. The first upload under a
new name registers it in `sensors`; a description can be added by hand
afterwards and is what the dashboard shows beside the name. More than one
station can report to the same server, and the dashboard switches between
them.

The dashboard is one Livewire page. At the top the current readings of the
chosen station, then the forecast for the next six hours, below them temperature and humidity (dew point on request)
and sea-level pressure on a strip of its own, over the last week by default,
with the min-max band behind each line and a strip of the whole record to
drag any window up to a month through; the bucket width follows the span on
screen. When the window holds noise, two more strips follow: the A-weighted
level with its LA90 to LA10 band and the loudest second, and the third-octave
spectrum as a waterfall, with a rain icon over the columns the microphone
heard rain in. Events entered by hand into `station_events` are marked on
the charts. Under the charts the three newest windows as they were
stored, the station's own report of how it was doing, and the site's
approximate location - one place, set in the component, so a second station
is shown on the first one's map. The window and the station are in the URL,
so a view can be linked to.

The forecast comes from a Python service beside the app. After every upload
the server sends it the station's last 60 days and stores the answer:
temperature as a range one to six hours ahead, and the chance of rain - and
the same temperature as the base model gave it before the station
correction. Folded under it, the last 30 days of forecasts are scored against
what the station then measured, for the chosen hour ahead. A chart shows by
day how much smaller the forecast's miss was than a naive guess's (the
temperature at the time, kept for the hours ahead), once as shown and once
for the base model, on the same hours: the gap between the two lines is what
the correction has learnt from the station's own misses, and a day a newly
trained model or a new version of the correction took over is marked. Below it how often the temperature
landed in the range and how wide the range was, the mean rain chance given
when it rained and when it did not, and a chart by the hour of the day the
forecast was for of how much warmer or colder the station read than
forecast - the morning sun on the shield reads warmer.
The models learnt the weather from eight years of ČHMÚ station records and
correct themselves for the station from its own history; at forecast time
they use nothing but the station's readings. How it works, how well it
scores and what it cannot do is in [`forecast/README.md`](forecast/README.md).

Rain is heard, not measured. Drops from the roof ring the plastic radiation
shield around 1 kHz while the top of the spectrum goes loud; tyres on a wet
road are as loud at 8 kHz but ring nothing. `App\ValueObject\RainDetector`
checks each ten-minute window for both. The thresholds come from one rain,
checked against the ČHMÚ gauge 3.5 km away and a log kept on the balcony,
and belong to this mounting; drizzle too fine to drip is not heard.

## Layout

| Path                | Contents                                                                                                                                                                                                                                                            |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `firmware/`         | Arduino sketches. Wiring, protocol and the hardware notes worth keeping are in [`firmware/README.md`](firmware/README.md); each build that went on a board, with its hardware and the server release it needs, in [`firmware/CHANGELOG.md`](firmware/CHANGELOG.md). |
| `forecast/`         | The forecast: fetching the ČHMÚ records, training, scoring, and the service. Its own [`README`](forecast/README.md) and [`CHANGELOG`](forecast/CHANGELOG.md), one entry per model that went on the server.                                                          |
| `app/Http/`         | The ingest endpoint, its form request, and the bearer token middleware.                                                                                                                                                                                             |
| `app/Enums/`        | `ProtocolVersion`, which maps a payload version to its decoder, and the bucket widths per span.                                                                                                                                                                     |
| `app/ValueObject/`  | Per-version decoding of a measurement payload.                                                                                                                                                                                                                      |
| `app/Models/`       | Sensors, measurements, station reports, forecasts and the hand-written events.                                                                                                                                                                                      |
| `app/Jobs/`         | `ForecastWeather`, which asks the forecast service after an upload and stores the answer; `BackfillForecastBase`, behind `php artisan forecast:backfill-base`, which fills in the base model's forecast on forecasts stored before the service returned it.         |
| `app/Livewire/`     | The dashboard component, with its view in `resources/views/livewire/`.                                                                                                                                                                                              |
| `database/seeders/` | A month of two stations' weather, the last three days of the first with noise and two showers, and two weeks of forecasts for each, for a dashboard without a device on the desk.                                                                                   |
| `docs/`             | The [API contract](docs/api.md), and what happens to a batch after it lands.                                                                                                                                                                                        |
| `docker/`           | nginx, PHP-FPM and supervisord config for the production image; the init script that creates the local test database.                                                                                                                                               |

## API

One endpoint, bearer auth against `SENSOR_API_TOKEN`, 10 requests per minute.

```
POST /api/v1/measurement
Authorization: Bearer <token>
```

Uploads are batches. The firmware buffers what it could not deliver and sends
it with the next window, so a batch is often a retry: `(sensor, timestamp)` is
unique and the write upserts, which makes a partially delivered batch safe to
send again. One invalid entry rejects the whole batch, so the firmware drops
an out-of-range reading before it reaches a window - otherwise it would wedge
every window queued behind it. Beside the measurements a batch may carry a
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
`FORECAST_URL` is optional too: the forecast service's address (locally
`http://127.0.0.1:8000` after `uv run serve.py` in `forecast/`); unset, no
forecasts are made. `FORECAST_HISTORY_SINCE`, a local date such as
`2026-09-17`, keeps readings from before it out of what the service learns
the station correction from - for when the station changed; unset, it gets
the last 60 days.

PostgreSQL everywhere, the same image as production: the dashboard averages
its buckets in SQL that only PostgreSQL speaks, so there is no SQLite to fall
back on. `MeasurementSeeder` writes a month of two stations at the reporting
interval, which is the fastest way to get something on the chart without a
device on the desk. The first station moves through all three protocols as
the real one did and carries noise for its last three days, two showers in
it, so the noise strips and the rain icons have something to draw; the
second has no microphone. `ForecastSeeder` adds a forecast from each
station's newest reading and one on every hour of the two weeks before,
synthetic but shaped like the service's answer: a correction that gets better
as the record grows, and a newly trained model that took over five days back.
The forecast and its accuracy panel show without the service running.

## Deploying

The `Dockerfile` builds one image: assets on Node, dependencies on Composer,
then nginx and PHP-FPM under supervisord on port 8080. The entrypoint caches
config, routes and views at start, because the environment only exists at run
time. It does not run migrations; do that as a step of your deploy.

The forecast service is a second image, built from `forecast/` with its own
`Dockerfile`, with the trained model mounted rather than built in. It needs
no public address; point `FORECAST_URL` at it on the internal network. See
[`forecast/README.md`](forecast/README.md#deploying). Forecasts stored before
the service returned the base model's forecast get it with
`php artisan forecast:backfill-base`, once, after both are deployed.
