# Forecast

A short-term forecast for the station: temperature, humidity and pressure
one to six hours ahead, and the chance of rain within those hours. It runs
from the station's own readings alone - no neighbouring stations, no
numerical weather model, no radar at forecast time. That is the point of it
and also its limit, see [What it cannot do](#what-it-cannot-do).

Each model that went on the server, with the data it was trained on and how
it scored, is in [`CHANGELOG.md`](CHANGELOG.md).

## How it works

**Trained on ČHMÚ, run on the balcony.** A month of balcony readings is far
too little to learn weather from, so the models learn it from the Czech
Hydrometeorological Institute's open data: 10-minute records of the
professional ("20000" series) stations, 2018 to 2025, the 32 of them below
700 m - about 1.7 million hourly examples. The ČHMÚ stations measure what the
balcony does (temperature, humidity, station pressure) plus precipitation,
which is only ever a training label. The data are CC BY 4.0, source ČHMÚ;
the dashboard credits them under the forecast.

**Inputs** (`features.py`, shared by training and the service, so the two can
never compute them differently): the current temperature and humidity and
their distance from the dew point; how temperature, humidity and pressure
changed over the last 1, 3, 6, 12, 24 and 48 hours; where the pressure sits
in its two-day swing; how unsettled the last three hours were (jitter of
temperature and humidity, whether the pressure fall is speeding up); rain in
the last hour and three hours; solar time of day and day of the year.
Pressure only ever enters as a change - its level mostly encodes a station's
elevation and would not carry over from one station to another. The jitter
is computed on readings rounded to ČHMÚ's resolution, so the balcony's finer
sensor does not look calmer than the training stations.

**Models** (`train.py`): scikit-learn's `HistGradientBoosting`, per horizon.
For each of temperature, humidity and pressure three quantile models - the
10th, 50th and 90th percentile of the change - so the forecast is a range
that should hold four readings in five, narrow in settled weather and wide
when it is not. For rain a classifier: the chance of at least 0.1 mm within
the next n hours. The rain inputs are hidden on 30 % of the training rows,
so the models cope when the balcony's rain source is missing.

**Correction for the station** (`correction.py`). A balcony is not a ČHMÚ
screen on a lawn: morning sun, a warm wall and its own sensor. On every run
the service forecasts the station's recent history too, compares it with
what was measured, and fits per variable and horizon a ridge regression of
its error on the solar hour and on the errors just verified; then it widens
or narrows the range until it holds 80 % of the station's own readings.
Nothing is stored - the correction is refitted each time and sharpens as the
record grows. It applies to temperature and humidity; correcting pressure
scored worse. The service answers with the forecast before the correction
too (`base`), so the dashboard can score what the correction adds.

**The service** (`serve.py`) is stateless and has no database. Laravel sends
it the station's last 60 days after every upload (`App\Jobs\ForecastWeather`)
and stores the answer in `forecasts`; the dashboard shows the newest one
while it starts from the current record. Temperature and rain are on the
page; humidity and pressure are kept in the row. Under it, the last 30 days
of forecasts are scored as shown and before the correction, on the same
hours, against a naive guess that the temperature stays as it is.

## Results

Scored on 2025 at four ČHMÚ stations the models never saw (Plzeň-Mikulka,
Cheb, Kuchařovice, Pardubice), mean absolute error, the model's median
against persistence ("nothing changes"):

| Ahead | Temperature    | Humidity     | Pressure        |
| ----- | -------------- | ------------ | --------------- |
| 1 h   | 0.50 / 0.82 °C | 2.7 / 3.6 %  | 0.19 / 0.30 hPa |
| 3 h   | 0.94 / 2.09 °C | 4.7 / 8.5 %  | 0.44 / 0.77 hPa |
| 6 h   | 1.38 / 3.66 °C | 6.4 / 14.4 % | 0.85 / 1.36 hPa |

The 10-90 % range held the truth 76-81 % of the time. Rain: Brier score
0.039 against 0.068 for the climatological rate at 1 h, 0.103 against 0.148
at 6 h; telling a coming rain from none while it is still dry (onset), ROC
AUC 0.86 at 1 h and 0.79 at 6 h.

On the balcony, correction fitted up to 18 September 2026 and scored on 19 to
24 September: temperature 6 h ahead 2.51 °C without it, 1.82 °C with it;
humidity 9.7 % and 7.0 %; the temperature range held 57 % of readings
without the correction and 74 % with it. Two weeks of record, so these move.

Training on 2018-2020 or on 2022-2024 scored the same on 2025: older years
do not make the models worse.

## What it cannot do

A single station sees weather only once it arrives. On 24 September 2026 it
rained from 06:20; the forecasts issued before gave 1-4 %. The air was
still fairly dry, the pressure falling only moderately, and the front coming
in from the west was invisible from the balcony. The correction made the
same morning worse: it had learnt that the sun warms the balcony after
sunrise, and that morning was overcast. A light or sky sensor on the station
would tell it which kind of morning it is.

## Running it

Python 3.14 and [uv](https://docs.astral.sh/uv/). Everything runs from this
directory; `data/` and `models/` are gitignored.

```
uv run fetch_chmi.py download   # ~15 000 CSVs, 3 GB, into data/raw; resumable
uv run fetch_chmi.py build      # one parquet per station, 170 MB, + data/stations.csv
uv run train.py                 # ~22 minutes on an M4, writes models/forecast.joblib
uv run evaluate_balcony.py      # scores it on data/balcony.csv, rain from Mikulka
uv run serve.py                 # the service on :8000
```

`evaluate_balcony.py` wants the station's record as `data/balcony.csv` with
the columns `timestamp,t,h,p` (Unix seconds, °C, %, hPa station pressure),
exported from the production database. It takes the rain labels, and the
stand-in for the microphone's rain detector, from the ČHMÚ gauge at
Plzeň-Mikulka, 3.5 km away.

The service reads `MODEL_PATH` (default `models/forecast.joblib`), `HOST`
and `PORT`, and reloads the model when the file changes. Laravel finds it
through `FORECAST_URL`; unset, no forecasts are made.

### The service's contract

```
POST /forecast
{"longitude": 13.40,
 "readings": [{"timestamp": 1790000000, "temperature": 9.7,
               "humidity": 76.0, "pressure": 976.6, "rain": null}, ...]}
```

Up to 60 days of readings: °C, %, station pressure in hPa, and rain in mm
per ten minutes where the station has a source for it (null or absent
otherwise). The answer:

```
{"issued_at": 1790000000, "model": "2026-09-24T08:40:43.136429+00:00",
 "corrected": true,
 "horizons": [{"hours": 1,
               "temperature": {"low": 12.4, "mid": 13.84, "high": 15.56},
               "humidity": {...}, "pressure": {...},
               "rain_probability": 0.023,
               "base": {"temperature": {"low": 12.1, "mid": 13.46, "high": 15.9},
                        "humidity": {...}}}, ...]}
```

`issued_at` is the latest reading's ten-minute window, `model` the bundle's
`trained_at`, `corrected` whether there was history enough (three days) for
the station correction. `base` is the same forecast before the correction,
for the two variables it corrects; pressure and rain are not corrected, so
theirs is the one above. Malformed requests get a 422 with the reason.
`GET /health` answers `{"status": "ok", "model": ...}`.

```
POST /base
{"longitude": 13.40, "since": 1789400000, "readings": [...]}
```

The base forecast for every reading from `since` on, for forecasts stored
before the service returned `base`:

```
{"model": "2026-09-24T08:40:43.136429+00:00",
 "forecasts": [{"issued_at": 1789400000,
                "horizons": [{"hours": 1, "temperature": {...},
                              "humidity": {...}}, ...]}, ...]}
```

The base models look 48 hours back and nothing else, so an answer is exactly
what the service gave at the time, as long as the readings start that far
before `since`. The correction learns from the whole history and is not
recomputed. `php artisan forecast:backfill-base` in the app asks a week at a
time, with three days of readings before it, and fills in only the forecasts
the model now running made.

## Deploying

`Dockerfile` builds from this directory: the dependencies into a virtualenv
with uv, then only Python, that virtualenv and the four service files - about
550 MB, the packages' own test suites stripped. The model is not in the
image: it is mounted at `/models`, so a retrained model is a file copy, not
a build. The container needs no domain or published port; Laravel reaches it
on the internal Docker network.

A new model: train, check `evaluate_balcony.py`, copy `forecast.joblib` over
the mounted one, and add an entry to the changelog.

The first deploy of a service that returns `base`: once the app is deployed
beside it, run `php artisan forecast:backfill-base` in the app container to
fill it in on the forecasts stored before. Do it before a new model goes
on: only the model that made a forecast can recompute its base.
