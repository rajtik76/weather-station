# Development

## The site

A station is whatever uploads under a `sensor_name`; the first upload registers it in `sensors`, a description can be added by hand. A header picker switches stations once there are two.

Three Livewire pages on the base `StationPage`. Window and station are in the URL.

| Page     | Path        | Shows                                                                                                                                                                                  |
| -------- | ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Overview | `/`         | current readings per channel with the last 24 h, next six hours with the verdict, the board's report, approximate location; the only self-refreshing page                              |
| Charts   | `/charts`   | temperature, humidity (dew point on request), sea-level pressure; last week by default, min-max band, whole-record strip to drag a window up to a month; bucket width follows the span |
| Forecast | `/forecast` | how the model works, the forecast now, the verdict by horizon, [scoring](scoring.md)                                                                                                   |

Charts, when the data exists:

- Noise strips: A-weighted level with LA90 to LA10 band and loudest second; third-octave spectrum waterfall with a rain icon over columns where rain was heard
- Light strip: lux in the shield, log scale
- Events from `station_events` (entered by hand) marked on the charts
- Below: the three newest windows as stored and the station's full report

Rain is detected by `App\ValueObject\RainDetector` per ten-minute window: ring around 1 kHz plus a loud top of the spectrum.

## Layout

| Path                | Contents                                                                                                                                                                                                                              |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `firmware/`         | Arduino sketches; [`README`](../firmware/README.md), [`CHANGELOG`](../firmware/CHANGELOG.md)                                                                                                                                          |
| `forecast/`         | ČHMÚ fetching, training, scoring, the service; [`README`](../forecast/README.md), [`CHANGELOG`](../forecast/CHANGELOG.md)                                                                                                             |
| `app/Http/`         | Ingest endpoint, form request, bearer token middleware                                                                                                                                                                                |
| `app/Enums/`        | `ProtocolVersion` (payload version to decoder), bucket widths per span                                                                                                                                                                |
| `app/ValueObject/`  | Per-version payload decoding; calculations for the pages: sea-level pressure, dew point, rain, forecast scores, number format                                                                                                         |
| `app/Models/`       | Sensors, measurements, station reports, forecasts, events                                                                                                                                                                             |
| `app/Queries/`      | Everything pages and jobs read: chart buckets, scoring, newest forecast and report, the one client of the forecast service, the station correction cached for a day                                                                   |
| `app/Jobs/`         | `ForecastWeather` (asks the service after every upload, stores the answer and the light experiment); `BackfillForecastBase` (`php artisan forecast:backfill-base`), `BackfillLightExperiment` (`php artisan forecast:backfill-light`) |
| `app/Livewire/`     | The three pages; views and partials in `resources/views/livewire/`                                                                                                                                                                    |
| `database/seeders/` | A month of two stations (first: last three days with noise and two showers, last day with light), two weeks of forecasts each                                                                                                         |
| `docs/`             | [API contract](api.md); KiCad projects in `docs/hardware/kicad/`                                                                                                                                                                      |
| `docker/`           | nginx, PHP-FPM, supervisord config for the production image; init script for the local test database                                                                                                                                  |

## Running it

Needs PHP 8.4, Node 24 and Docker. No paid Flux components, no `auth.json`.

```
docker compose up -d   # PostgreSQL 18 on 5432, with a second database for the tests
composer setup         # install, .env, app key, migrate, build assets
php artisan migrate:fresh --seed   # a month of sample data; wipes the local database
composer dev           # server, queue worker, logs, vite
composer test
composer review        # rector, phpstan, tests
```

PostgreSQL everywhere (same image as production); the charts average buckets in SQL only PostgreSQL speaks.

### `.env`

| Variable                 | Meaning                                                                                                                                                        |
| ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SENSOR_API_TOKEN`       | Bearer token the firmware sends; the endpoint denies everything while empty                                                                                    |
| `SENSOR_HEARTBEAT_URL`   | Optional; requested after each stored batch (push monitor)                                                                                                     |
| `FORECAST_URL`           | Optional; forecast service address, locally `http://127.0.0.1:8000` after `uv run serve.py` in `forecast/`; unset, no forecasts                                |
| `FORECAST_HISTORY_SINCE` | Local date such as `2026-09-17`; earlier readings stay out of the station correction; invalid value is reported and ignored                                    |
| `FORECAST_NWP_URL`       | Numerical weather model stored with every forecast (not scored), default Open-Meteo `https://api.open-meteo.com/v1/forecast`; empty disables it (the tests do) |

### Sample data

- `MeasurementSeeder`: a month of two stations at the reporting interval; first station has noise for its last three days (two showers) and light for the last one, the second has neither
- `ForecastSeeder`: a forecast on every ten-minute slot of each station's last two weeks; synthetic, shaped like the service's answer (improving correction, a new model taking over five days back)
- `LightForecastSeeder` (local and testing only): a synthetic light experiment on forecasts issued while the station had light
- The forecast and accuracy panel show without the service running

## Deploying

- `Dockerfile`: one image, assets on Node, dependencies on Composer, nginx + PHP-FPM + Laravel scheduler under supervisord on port 8080
- Entrypoint caches config, routes and views at start; it does not run migrations - do that in the deploy
- Forecast service: second image from `forecast/`, model mounted; no public address, point `FORECAST_URL` at it on the internal network; see [`forecast/README.md`](../forecast/README.md#deploying)
- Once, after both are deployed: `php artisan forecast:backfill-base`
