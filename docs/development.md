# Development

## Purpose and current scope

The project records temperature, humidity and pressure from a balcony
station in Plzeň. Its next stage is a correction of radiation shield heating,
with ČHMÚ Plzeň-Mikulka as the professional temperature reference.

The current pages display original sensor measurements. A PHP correction
model and automated reference ingestion are available through Artisan;
displaying the estimate and a historical comparison page remain to be
implemented. Mikulka is about 3.5 km away;
its temperature is a reference with a different local environment.

Imported reference temperatures and calibration results are stored in
PostgreSQL. Previously downloaded reference files may also be kept locally
under `storage/app/private/chmu/`, outside version control and the image.

## Pages

All three pages extend `StationPage`. A station is registered by the first upload
under a new `sensor_name`. The picker appears once two stations exist; its
selection survives navigation through the `sensor` query parameter.
Links use station slugs so they remain stable across reseeding.

| Page     | Path          | Content                                                                                                        |
| -------- | ------------- | -------------------------------------------------------------------------------------------------------------- |
| Overview | `/`           | Current measurements, last 24 hours, hardware, station report and approximate location; polls every 60 seconds |
| Charts   | `/charts`     | Measurement history with shade correction, time window, channel switches, station events and raw transmissions |
| Mikulka  | `/comparison` | Daily sensor, shade and ČHMÚ comparison up to 30 days back; 30-day deviation statistics                        |

The charts average measurements into PostgreSQL buckets, preserving gaps
and sample extremes. The selected window is limited to a month, with a
navigator over the whole record. Pressure is stored as station pressure
and reduced to sea level for display.

Sound and light appear when the selected station has sent them. Light is
measured behind the radiation shield's louvers. The sound spectrum, LAeq and
LA90 provide an experimental rain indication through `RainDetector`.

## Structure

| Path                   | Content                                                                                     |
| ---------------------- | ------------------------------------------------------------------------------------------- |
| `firmware/`            | ESP32 firmware, wiring and protocol documentation                                           |
| `app/Http/`            | Measurement ingest, validation and bearer token authentication                              |
| `app/Models/`          | Sensors, measurements, station reports and station events                                   |
| `app/Queries/`         | Data access for pages and chart buckets                                                     |
| `app/ValueObject/`     | Payload decoding, units, pressure reduction, dew point, light, sound and chart calculations |
| `app/Livewire/`        | Shared station state and the three page components                                          |
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
| `DISCORD_*`            | Optional rain confirmation bot, see below               |

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

## Rain confirmation on Discord

`rain:watch` runs every two minutes. When `RainDetector` changes its mind
about a station's newest noise window, it posts "Is it raining?" to a Discord
channel with ✅ and ❌ buttons; ✅ always means raining. Windows older than
30 minutes are ignored rather than read as dry. `/rain` and `/dry` log a
report without a prompt, together with what the detector hears at that
moment. Every prompt and answer lands in `rain_reports` as labels for
calibrating the detector.

A prompt Discord refuses is posted again on the next run. Discord calls
`POST /api/discord/interactions`, which accepts only requests signed with the
application's Ed25519 key within the last five minutes, and takes reports
only from the configured channel. The route is exempt from the sensor
ingest's rate limit. Setup:

| Variable                 | Source in the Developer Portal            |
| ------------------------ | ----------------------------------------- |
| `DISCORD_APPLICATION_ID` | General Information                       |
| `DISCORD_PUBLIC_KEY`     | General Information                       |
| `DISCORD_BOT_TOKEN`      | Bot, Reset Token                          |
| `DISCORD_CHANNEL_ID`     | Channel, Copy Channel ID (Developer Mode) |

Invite the bot with the `bot` and `applications.commands` scopes and the
View Channels and Send Messages permissions. After deploying, run
`php artisan discord:register-commands` once and set the Interactions
Endpoint URL to `https://<host>/api/discord/interactions`; Discord verifies
it on save. Without a bot token and channel, `rain:watch` does nothing.

## Temperature correction work

Use `station_events` to distinguish hardware periods. The radiation shield
was installed on 16 September 2026 at 15:00 Europe/Prague. The following
sensor changes on 23 and 30 September are informational markers. Station
events do not block calibration or invalidate an existing model.

`temperature:import-reference` downloads yesterday and today in UTC from
ČHMÚ open data. The scheduler runs it hourly at :15. Use `--date=YYYY-MM-DD`
for one available daily file, `--month=YYYY-MM` for a published monthly
file, or pass local JSON paths to import an archive. Run `--days=30` once
to backfill monthly archives and the current month's dated daily files. Upserts use the
station and observation timestamp; invalidated observations become null.
Data attribution: ČHMÚ Plzeň-Mikulka, CC BY 4.0. Daily files are short-lived,
so run the scheduler continuously; monthly archives arrive later.

`temperature:calibrate <sensor-slug> --from=YYYY-MM-DD
--validate-from=YYYY-MM-DD --until=YYYY-MM-DD` fits on the first period and
scores the later period, with exclusive date boundaries in Europe/Prague.
Optional
`--latitude`, `--longitude` and `--wall-azimuth` describe the installation;
defaults are the balcony coordinates and a 71.8° facade clockwise from true north.
`temperature:retrain` runs every Monday at 00:15 Europe/Prague. For each
calibrated sensor it fits on the 14 days before local midnight and stores
the model as under validation for the next three days, on readings it has
never seen. It corrects nothing meanwhile; `/comparison` charts its
improvement over the model in use on those days and lists every calibration,
its scores and when it was in use under Model history. `temperature:validate` runs daily at 00:10 and scores every
model whose validation days are over: it is accepted only when it passes the
acceptance rules and beats the model in use on the same observations.
`CorrectedTemperature` applies accepted, compatible calibrations after their
validation period, including across station events. Its batch and individual
calculations use the same six-hour light history for each observation.

`TemperatureDataset` reads one sensor's unchanged measurements, builds
causal features and pairs each window end with its nearest ten-minute
ČHMÚ T observation within 90 seconds. A balcony reading is a ten-minute
mean; ČHMÚ T is a temperature at the observation time and location. The
window and reference therefore have a temporal representativeness limit.
Missing references are neither interpolated nor carried forward.

`ShieldFeatures` uses lux hinges at 500, 1200 and 1800 lx, gated by solar
elevation and whether the Sun is in front of the calibrated wall. Their
20- and 60-minute exponential histories allow cooling after shade arrives.
Solar geometry follows [NOAA's solar position equations](https://gml.noaa.gov/grad/solcalc/solareqns.PDF).
`NonNegativeRegression` fits nine non-negative coefficients with an L2
penalty of 0.001 per observation. No constant offset is fitted: differences
between the two locations must not become a universal temperature offset.
The knots, time constants and regularization are initial modeling choices,
not independently measured physical properties.

`temperature_calibrations` keeps geometry, feature version, coefficients,
training and validation boundaries, limits, acceptance and scores. The
minimum data and acceptance rules are described in the README. Rejected
calibrations are retained for inspection and never used for inference.
Validation periods are not refitted after scoring. Keep a new set of later
days for evaluating changes suggested by these scores.

`CorrectedTemperature::at()` selects a compatible accepted calibration for
the sensor whose validation ended before the requested reading. It reads
six hours of local history, uses the identical feature calculation and
does not access ČHMÚ. No estimate is returned without a full hour of
uninterrupted light readings, after gaps above 15 minutes, or beyond
120% of the calibration's maximum lux or heating. The lux limit applies
while the shield faces the Sun. `temperature:show <sensor-slug>` prints the
newest measured temperature, shade estimate and heating correction.

The correction currently operates through these backend APIs and commands;
the page payloads and charts continue to show original temperatures.
