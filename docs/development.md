# Development

## Purpose and current scope

The project records temperature, humidity and pressure from a balcony
station in Plzeň. Its next stage is a correction of radiation shield heating,
with ČHMÚ Plzeň-Mikulka as the professional temperature reference.

The current application displays original sensor measurements. A corrected
temperature estimate, automated reference ingestion and a historical
comparison page are still to be implemented. Mikulka is about 3.5 km away;
its temperature is a reference with a different local environment.

Downloaded reference files are kept locally under
`storage/app/private/chmu/`, outside version control and the application image.

## Pages

Both pages extend `StationPage`. A station is registered by the first upload
under a new `sensor_name`. The picker appears once two stations exist; its
selection survives navigation through the `sensor` query parameter.
Links use station slugs so they remain stable across reseeding.

| Page     | Path      | Content                                                                                                        |
| -------- | --------- | -------------------------------------------------------------------------------------------------------------- |
| Overview | `/`       | Current measurements, last 24 hours, hardware, station report and approximate location; polls every 60 seconds |
| Charts   | `/charts` | Measurement history, time window, channel switches, station events and the newest three raw transmissions      |

The charts average measurements into PostgreSQL buckets, preserving gaps
and sample extremes. The selected window is limited to a month, with a
navigator over the whole record. Pressure is stored as station pressure
and reduced to sea level for display.

Sound and light appear when the selected station has sent them. Light is
measured behind the radiation shield's louvers. The sound spectrum provides
an experimental rain indication through `RainDetector`.

## Structure

| Path                   | Content                                                                                     |
| ---------------------- | ------------------------------------------------------------------------------------------- |
| `firmware/`            | ESP32 firmware, wiring and protocol documentation                                           |
| `app/Http/`            | Measurement ingest, validation and bearer token authentication                              |
| `app/Models/`          | Sensors, measurements, station reports and station events                                   |
| `app/Queries/`         | Data access for pages and chart buckets                                                     |
| `app/ValueObject/`     | Payload decoding, units, pressure reduction, dew point, light, sound and chart calculations |
| `app/Livewire/`        | Shared station state and the two page components                                            |
| `resources/views/`     | Page templates and shared Blade components                                                  |
| `resources/js/charts/` | ECharts strips, navigator, events, cursor and zoom                                          |
| `database/seeders/`    | Demonstration measurements and events                                                       |
| `docker/`              | Production nginx, PHP-FPM, supervisord and local PostgreSQL initialization                  |
| `docs/hardware/kicad/` | Hardware designs and their generation tools                                                 |

The hardware generation scripts under `docs/hardware/kicad/` belong to the
circuit designs and remain part of the project.

## Local development

Requires PHP 8.4, Node 24 and Docker. PostgreSQL uses the same
`postgres:18-alpine` image locally and in production. Laravel Herd serves
the site. No Flux Pro license is required.

```sh
docker compose up -d
composer setup
npm run dev
```

`php artisan migrate:fresh --seed` is an optional destructive reset of the
local database. The two sample stations contain a month of weather based
on ČHMÚ Mikulka observations from August 2025. Their station differences,
sample extremes, sound and light are synthetic. The first station has
sound for its last three days and light for its last day.

The sample pressure has been adjusted from Mikulka's 359.8 m elevation to
the balcony's 345 m using the same dry-air hypsometric formula as the display.
The fixture records both elevations. This avoids introducing an elevation
offset when the dashboard reduces the sample station pressure to sea level.
Synthetic variation uses a fixed random seed. The stored protocol units
are documented in [`api.md`](api.md).

## Configuration

| Variable               | Purpose                                                 |
| ---------------------- | ------------------------------------------------------- |
| `SENSOR_API_TOKEN`     | Bearer token for ingest; an empty token disables access |
| `SENSOR_HEARTBEAT_URL` | Optional monitor ping after a batch has been stored     |
| `DB_*`                 | PostgreSQL connection                                   |
| `APP_NAME`             | Application name; `Balcony Station` in `.env.example`   |

The measurement protocol and response remain documented in
[`api.md`](api.md). Storage uses UTC; presentation uses Europe/Prague.

## Presentation contracts

- `LocalTime::wallClockMs()` includes the Czech UTC offset so ECharts can render local time with `useUTC: true`. It is a display coordinate, distinct from the real epoch used for zooming and comparisons with the current time.
- `LocalTime::stamp()` and `formatStamp()` in `resources/js/charts/format.js` share the `j.n.Y H:i` format.
- `Channel` spells out complete Tailwind utility names for source detection.
- Chart event titles pass through `escapeHtml()` before insertion into `innerHTML`.
- The reset zoom button remains rendered while disabled so other controls stay in place when zoom becomes available.

## Checks

Code formatting, static analysis and tests:

```sh
vendor/bin/pint --dirty
composer stan
npm run test
php artisan test --parallel
npm run build
```

`composer review` runs formatting, Rector, PHPStan, JavaScript tests and
PHP tests. Regression coverage includes ingest authentication, validation,
resends, protocol versions, station selection and chart behavior.

## Deployment

The application uses one image from the root `Dockerfile`: nginx, PHP-FPM
and the Laravel scheduler on port 8080. The entrypoint caches configuration,
routes and views. Migrations run separately during deployment.

## Temperature correction work

Use `station_events` to distinguish hardware periods. The radiation shield
was installed on 16 September 2026 at 15:00 Europe/Prague. The following
sensor changes on 23 and 30 September mark separate calibration periods.

The next stage will pair the original temperature, its corrected estimate
and ČHMÚ Mikulka observations in time. Evaluation must use days excluded
from calibration and compare the corrected estimate with the original
measurement on the same reference timestamps. Reference outages must
remain missing data.
