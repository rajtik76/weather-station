# Weather forecast station

[![CI](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml/badge.svg)](https://github.com/rajtik76/weather-station/actions/workflows/ci.yml)
[![Site](https://status.rajtik.com/api/badge/19/status?label=site)](https://status.rajtik.com)
[![Readings](https://status.rajtik.com/api/badge/18/status?label=readings)](https://status.rajtik.com)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE.md)

Can a balcony weather station forecast its own next six hours better than
assuming nothing changes? That is the question this project asks, and the
answer is on the page. A small model trained on eight years of station records
from the Czech Hydrometeorological Institute (ČHMÚ) forecasts temperature and
the chance of rain one to six hours ahead from the station's own readings
alone, and every forecast is scored against what the station then measured.
The front page sets the last 30 days' verdict beside the next six hours.

The station is how the question gets asked. An ESP32 reads temperature and
humidity from an SHT4x outside, the light from a VEML7700 beside it and
pressure from a BMP280 indoors every half minute, listens to an INMP441
microphone in the same shield all the time, folds it all into ten-minute
windows, and uploads them to a Laravel API that stores the windows and draws
them. The microphone's spectrum also tells when it rains.

```
SHT4x    --I2C--+
VEML7700 --I2C--+
BMP280   --I2C--+--> ESP32 --HTTPS--> Laravel API --> PostgreSQL
INMP441  --I2S--+                       |    |
                                        |  forecast service (Python)
                                   Livewire pages
```

Running at [weather.rajtik.com](https://weather.rajtik.com).

## The forecast

The forecast comes from a Python service beside the app. After every upload
the server sends it the station's last 60 days and stores the answer:
temperature as a range one to six hours ahead, and the chance of rain - and
the same temperature as the base model gave it before the station
correction. The models learnt the weather from eight years of ČHMÚ station
records and correct themselves for the station from its own history; at
forecast time they use nothing but the station's readings.

A forecast has to beat the naive guess: the temperature at the time it was
made, kept for the hours ahead. The temperature changes little in an hour, so
the guess is hard to beat that close and easier six hours on. The verdict is
therefore given for six hours ahead, with every shorter horizon beside it, in
three numbers over the last 30 days:

- **skill** - how much smaller the forecast's miss was than the naive
  guess's. Below zero the forecast did worse, and the page says so.
- **mean miss** in °C, beside the naive guess's.
- **in range** - how often the reading landed inside the forecast range. The
  range is drawn to hold eight readings in ten, so 80 % is on target and less
  means it was drawn too narrow.

Until a day of forecasts has come true, the verdict says it is too early.

The forecast page has the same scoring for every hour ahead and, for one at a
time, a chart of the skill by day, once as shown and once for the base model, on the same
hours: the gap between the two lines is what the correction has learnt from
the station's own misses, and a day a newly trained model or a new version of
the correction took over is marked. Below it how wide the range was, the
mean rain chance given when it rained and when it did not, and a chart by the
hour of the day the forecast was for of how much warmer or colder the station
read than forecast - the morning sun on the shield reads warmer. How it
works, how well it scores and what it cannot do is in
[`forecast/README.md`](forecast/README.md).

Beside the balcony's line runs a reference: every night the same model,
without a station correction, forecasts the day before at the ČHMÚ station
Plzeň-Mikulka, 3.5 km away, from the 10-minute record ČHMÚ publishes, and
those forecasts are scored the same way. Both lines dropping means the
weather was hard to forecast; only the balcony's dropping means it was the
balcony. `php artisan forecast:reference` does it, scheduled at 01:00 UTC; the
station is stored as a sensor of its own and kept out of the picker.

## Sensor accuracy

This is a hobby station, and the readings should be read as such. The SHT4x
sits in a passive radiation shield on an east-facing balcony, and on a clear
morning the sun hits it head on: for an hour or two the temperature then runs
more than 10 °C above the real air temperature, and the band behind the line
shows how far the samples inside a window spread. The shield went up in
September 2026 and took some of it away, not enough.

There is no fix coming. A properly ventilated site or an aspirated shield is
more than a balcony allows, and the point of the project is the forecast, not
a reference instrument. The forecast is scored against these same readings,
sun included, and the chart by the hour of the day shows what that does to
it. Overnight and under cloud the numbers are as good as an SHT4x gets; on a
sunny morning they are not the air temperature.

The VEML7700 sits in the same shield, behind its louvers. It sees a fraction
of the open sky's light, so its lux are the shield's: the shape of the day is
real, the numbers do not compare with a station in the open.

## What it does

A station is whatever uploads under a `sensor_name`. The first upload under a
new name registers it in `sensors`; a description can be added by hand
afterwards. More than one station can report to the same server, and a
picker in the header switches between them once there are two.

The site is three Livewire pages on one base, `StationPage`. The overview (`/`)
has the current readings of every channel with the last 24 hours under each,
the next six hours with the verdict beside them, the board's own report of
how it is doing and the site's approximate location; it is the only page that
refreshes itself. The charts page (`/charts`) has temperature and humidity
(dew point on request) and sea-level pressure on a strip of its own, over the
last week by default, with the min-max band behind each line and a strip of
the whole record above them to drag any window up to a month through; the
bucket width follows the span on screen. When the window holds noise, two
more strips follow: the A-weighted level with its LA90 to LA10 band and the
loudest second, and the third-octave spectrum as a waterfall, with a rain
icon over the columns the microphone heard rain in. A station that sends
light gets one more, the lux in the shield on a log scale. Events entered by
hand into `station_events` are marked on the charts. Under them the three
newest windows as they were stored and the station's report in full. The
forecast page (`/forecast`) is the model: how it works, the forecast now, the
verdict by horizon and the scoring described above. The window and the
station are in the URL, so a view can be linked to.

Rain is heard, not measured. Drops from the roof ring the plastic radiation
shield around 1 kHz while the top of the spectrum goes loud; tyres on a wet
road are as loud at 8 kHz but ring nothing. `App\ValueObject\RainDetector`
checks each ten-minute window for both. The thresholds come from one rain,
checked against the ČHMÚ gauge 3.5 km away and a log kept on the balcony,
and belong to this mounting; drizzle too fine to drip is not heard.

## Layout

| Path                | Contents                                                                                                                                                                                                                                                                                                                                                             |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `firmware/`         | Arduino sketches. Wiring, protocol and the hardware notes worth keeping are in [`firmware/README.md`](firmware/README.md); each build that went on a board, with its hardware and the server release it needs, in [`firmware/CHANGELOG.md`](firmware/CHANGELOG.md).                                                                                                  |
| `forecast/`         | The forecast: fetching the ČHMÚ records, training, scoring, and the service. Its own [`README`](forecast/README.md) and [`CHANGELOG`](forecast/CHANGELOG.md), one entry per model that went on the server.                                                                                                                                                           |
| `app/Http/`         | The ingest endpoint, its form request, and the bearer token middleware.                                                                                                                                                                                                                                                                                              |
| `app/Enums/`        | `ProtocolVersion`, which maps a payload version to its decoder, and the bucket widths per span.                                                                                                                                                                                                                                                                      |
| `app/ValueObject/`  | Per-version decoding of a measurement payload, and what the pages compute from the readings: sea-level pressure, dew point, the rain the microphone heard, the forecast's scores, the page's number format.                                                                                                                                                          |
| `app/Models/`       | Sensors, measurements, station reports, forecasts and the hand-written events.                                                                                                                                                                                                                                                                                       |
| `app/Queries/`      | Everything the pages and jobs read: the chart buckets, the scoring, the newest forecast and report, the ČHMÚ day files, and the one client of the forecast service.                                                                                                                                                                                                  |
| `app/Jobs/`         | `ForecastWeather`, which asks the forecast service after an upload and stores the answer; `BackfillForecastBase`, behind `php artisan forecast:backfill-base`, which fills in the base model's forecast on forecasts stored before the service returned it; `ForecastReferenceDay`, behind `php artisan forecast:reference`, which forecasts a day of Plzeň-Mikulka. |
| `app/Livewire/`     | The three pages on `StationPage`, with their views and partials in `resources/views/livewire/`.                                                                                                                                                                                                                                                                      |
| `database/seeders/` | A month of two stations' weather, the last three days of the first with noise and two showers, its last day with light, and two weeks of forecasts for each, for the pages without a device on the desk.                                                                                                                                                             |
| `docs/`             | The [API contract](docs/api.md), and what happens to a batch after it lands; the KiCad projects of the station and the shield hub in `docs/hardware/kicad/`.                                                                                                                                                                                                         |
| `docker/`           | nginx, PHP-FPM and supervisord config for the production image; the init script that creates the local test database.                                                                                                                                                                                                                                                |

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

Needs PHP 8.4, Node 24 and Docker. The pages use no paid Flux
components, so there is nothing to buy and no `auth.json` to fill in.

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
`2026-09-17`, keeps readings from before it out of what the station
correction learns from - for when the station changed; the base models still
get the last 60 days. A value that is not a date is reported and ignored.

PostgreSQL everywhere, the same image as production: the charts average
its buckets in SQL that only PostgreSQL speaks, so there is no SQLite to fall
back on. `MeasurementSeeder` writes a month of two stations at the reporting
interval, which is the fastest way to get something on the chart without a
device on the desk. The first station moves through all four protocols as
the real one did: noise for its last three days, two showers in it, and
light for the last one, so the noise and light strips and the rain icons
have something to draw; the second has neither. `ForecastSeeder` adds a
forecast from each station's newest reading and one on every hour of the two
weeks before, synthetic but shaped like the service's answer: a correction
that gets better as the record grows, and a newly trained model that took
over five days back.
The forecast and its accuracy panel show without the service running.

## Deploying

The `Dockerfile` builds one image: assets on Node, dependencies on Composer,
then nginx, PHP-FPM and Laravel's scheduler under supervisord on port 8080. The entrypoint caches
config, routes and views at start, because the environment only exists at run
time. It does not run migrations; do that as a step of your deploy.

The forecast service is a second image, built from `forecast/` with its own
`Dockerfile`, with the trained model mounted rather than built in. It needs
no public address; point `FORECAST_URL` at it on the internal network. See
[`forecast/README.md`](forecast/README.md#deploying). Forecasts stored before
the service returned the base model's forecast get it with
`php artisan forecast:backfill-base`, once, after both are deployed. The
Plzeň-Mikulka reference fills its first month with
`php artisan forecast:reference --days=30`, once; ČHMÚ keeps about a month.
