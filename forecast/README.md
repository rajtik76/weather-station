# Forecast

Temperature, humidity and pressure 1 to 6 hours ahead, and the chance of rain within those hours, from the station's own readings alone (no neighbouring stations, no numerical weather model, no radar at forecast time). Model history: [`CHANGELOG.md`](CHANGELOG.md).

## How it works

### Training data

- ČHMÚ open data (CC BY 4.0, credited in the site footer and on the forecast page): 10-minute records of the professional ("20000" series) stations, 2018 to 2025, the 32 below 700 m; about 1.7 million hourly examples
- Measures temperature, humidity, station pressure, plus precipitation (training label only)

### Inputs

`features.py`, shared by training and the service:

- Current temperature and humidity, and their distance from the dew point
- Change of temperature, humidity and pressure over 1, 3, 6, 12, 24, 48 h
- Pressure position in its two-day swing
- Unsettledness of the last 3 h: jitter of temperature and humidity, whether the pressure fall is speeding up
- Rain in the last 1 h and 3 h
- Solar time of day, day of the year
- Pressure only as a change (its level encodes elevation)
- Jitter computed on readings rounded to ČHMÚ's resolution

### Models

`train.py`: scikit-learn `HistGradientBoosting`, per horizon.

- Temperature, humidity, pressure: three quantile models (10th, 50th, 90th percentile of the change); the range should hold 4 readings in 5
- Rain: classifier for at least 0.1 mm within the next n hours
- Rain inputs hidden on 30 % of training rows (the balcony may have no rain source)
- `nest_rain()` in `forecast.py` caps the 1 h rain chance by the 2 h one (alone it gave 50-100 % on dry balcony days while longer horizons said 1-10 %)

### Station correction

`correction.py`; `POST /correction` fits it, Laravel caches the answer for a day and sends it back with every forecast.

- The service forecasts the station's recent history and compares it with what was measured
- Per variable and horizon, a ridge regression of the error on solar time and on the errors just verified
- Range widened or narrowed to hold 80 % of the station's readings
- Solar time as bins: 20 minutes from 5 to 12 h, an hour elsewhere
- Applies to temperature and humidity; correcting pressure scored worse
- The answer also carries the forecast before the correction (`base`)

### Light experiment

`light_correction.py`; `POST /light-correction` fits it, `POST /light-forecast` issues it. Scored beside the shown forecast, never shown as the forecast ([`docs/scoring.md`](../docs/scoring.md#light-experiment)).

- Temperature correction like the station correction, its solar inputs scaled by how lit the shield is against the same half hour of the 14 days before (0-1; below 100 lx expected, 1)
- Solar inputs halved from 4 h ahead
- A reading counts for a past moment only once it had arrived (`received_at`), so a buffered upload cannot teach the past

### Service

`serve.py`: stateless, no database.

- After every upload (`App\Jobs\ForecastWeather`), Laravel sends the last 56 hours and the cached correction; the answer is stored in `forecasts`
- Once a day Laravel sends the last 60 days to `POST /correction`, with `since` from `FORECAST_HISTORY_SINCE` if set (shield went up 16 September 2026, production learns from the 17th); a `409` for a stale correction refits it at once
- Page shows temperature and rain; humidity and pressure stay in the row
- Last 30 days of forecasts are scored as shown and before the correction, on the same hours, against persistence ([`docs/scoring.md`](../docs/scoring.md))

## Results

Scored on 2025 at four ČHMÚ stations the models never saw (Plzeň-Mikulka, Cheb, Kuchařovice, Pardubice). Mean absolute error, model median / persistence:

| Ahead | Temperature    | Humidity     | Pressure        |
| ----- | -------------- | ------------ | --------------- |
| 1 h   | 0.50 / 0.82 °C | 2.7 / 3.6 %  | 0.19 / 0.30 hPa |
| 3 h   | 0.94 / 2.09 °C | 4.7 / 8.5 %  | 0.44 / 0.77 hPa |
| 6 h   | 1.38 / 3.66 °C | 6.4 / 14.4 % | 0.85 / 1.36 hPa |

- 10-90 % range held the truth 76-81 % of the time
- Rain Brier score vs climatological rate: 0.039 / 0.068 at 1 h, 0.103 / 0.148 at 6 h
- Rain onset (still dry): ROC AUC 0.86 at 1 h, 0.79 at 6 h
- Training on 2018-2020 or 2022-2024 scored the same on 2025

Balcony, correction fitted up to 18 September 2026, scored 19 to 24 September (two weeks of record, will move):

| 6 h ahead                  | Without correction | With correction |
| -------------------------- | ------------------ | --------------- |
| Temperature                | 2.51 °C            | 1.82 °C         |
| Humidity                   | 9.7 %              | 7.0 %           |
| Temperature range coverage | 57 %               | 74 %            |

Bins vs two harmonics, walked forward day by day, fitted only on readings from 17 September (after the shield), scored 20 to 26 September:

| Case                                    | Bins    | Harmonics | No correction |
| --------------------------------------- | ------- | --------- | ------------- |
| Temperature 1 h ahead                   | 0.89 °C | 0.99 °C   | 0.92 °C       |
| 6 to 11 h, 2 h ahead                    | 1.74 °C | 2.25 °C   |               |
| 6 to 11 h, 3 h ahead                    | 2.03 °C | 2.45 °C   |               |
| 22 September (shade morning), 3 h ahead | 1.55 °C | 2.82 °C   | 0.90 °C       |

## Limitations

- A single station sees weather only once it arrives: on 24 September 2026 rain began at 06:20, forecasts issued before gave 1-4 % (air still fairly dry, pressure falling moderately, front invisible from the balcony)
- The correction made that morning worse: it learnt the sun warms the shield after sunrise, the morning was overcast; from temperature alone it cannot tell the two kinds of morning apart
- Sunny mornings are where the correction pays (halves misses of 3-5 °C)

## Running it

Python 3.14 and [uv](https://docs.astral.sh/uv/); run from this directory (`data/` and `models/` are gitignored).

```
uv run fetch_chmi.py download   # ~15 000 CSVs, 3 GB, into data/raw; resumable
uv run fetch_chmi.py build      # one parquet per station, 170 MB, + data/stations.csv
uv run train.py                 # ~22 minutes on an M4, writes models/forecast.joblib
uv run evaluate_balcony.py      # scores it on data/balcony.csv, rain from Mikulka
uv run serve.py                 # the service on :8000
```

- `evaluate_balcony.py` needs `data/balcony.csv` with columns `timestamp,t,h,p` (Unix seconds, °C, %, hPa station pressure) exported from the production database; rain labels (stand-in for the microphone detector) come from the ČHMÚ gauge at Plzeň-Mikulka, 3.5 km away
- Service env: `MODEL_PATH` (default `models/forecast.joblib`), `HOST`, `PORT`; reloads the model when the file changes
- Laravel reaches it through `FORECAST_URL`; unset, no forecasts

### The service's contract

```
POST /correction
{"longitude": 13.40, "since": 1789596000,
 "readings": [{"timestamp": 1790000000, "temperature": 9.7,
               "humidity": 76.0, "pressure": 976.6, "rain": null}, ...]}
```

- Up to a year of readings: °C, %, station pressure in hPa, rain in mm per ten minutes (null or absent without a source)
- `since` optional: the correction learns only from readings from then on; base models still read all (they need 48 h behind every forecast, as do the errors the correction takes as inputs)

```
{"model": "2026-09-24T08:40:43.136429+00:00", "correction": 3,
 "targets": {"T_1h": {"intercept": 0.41,
                      "coefficients": {"solar_0": -0.12, ..., "error_same": 0.3, "error_1h": 0.25},
                      "widen": 0.08}, ...}}
```

- One target per corrected variable and horizon; fewer, or none, while the history is short (3 days) or never verified
- Plain numbers: the ridge regression's intercept and weights by input name, and how far the range is widened (negative: narrowed)

```
POST /forecast
{"longitude": 13.40, "correction": {...},
 "readings": [{"timestamp": 1790000000, ...}, ...]}
```

- Readings as above; 54 h before the latest suffice (48 h behind the forecast issued 6 h earlier, whose verified error the correction reads), Laravel sends 56
- `correction` optional: the `POST /correction` answer as received; without it the forecast is the base models'
- A correction fitted for another model or correction version gets 409; malformed requests get 422 with the reason

```
{"issued_at": 1790000000, "model": "2026-09-24T08:40:43.136429+00:00",
 "corrected": true, "correction": 3,
 "horizons": [{"hours": 1,
               "temperature": {"low": 12.4, "mid": 13.84, "high": 15.56},
               "humidity": {...}, "pressure": {...},
               "rain_probability": 0.023,
               "base": {"temperature": {"low": 12.1, "mid": 13.46, "high": 15.9},
                        "humidity": {...}, "rain_probability": 0.023}}, ...]}
```

- `issued_at`: the latest reading's ten-minute window
- `model`: the bundle's `trained_at`
- `corrected`: whether the correction sent covered every corrected target
- `correction`: version of the correction logic (`CORRECTION_VERSION` in `correction.py`, raised with every change, logged in the changelog)
- `base`: forecast before the correction, for the two corrected variables; pressure is not corrected, so its forecast is the one above
- `base.rain_probability`: the classifier's value before `nest_rain()` capped the first hour; a capped run shows as `data->0->'base'->>'rain_probability'` above `data->0->>'rain_probability'`
- `GET /health` answers `{"status": "ok", "model": ..., "correction": 3}`

```
POST /base
{"longitude": 13.40, "since": 1789400000, "readings": [...]}
```

Base forecast for every reading from `since` on, for forecasts stored before the service returned `base`:

```
{"model": "2026-09-24T08:40:43.136429+00:00",
 "forecasts": [{"issued_at": 1789400000,
                "horizons": [{"hours": 1, "temperature": {...},
                              "humidity": {...}, "rain_probability": 0.023}, ...]}, ...]}
```

- Base models look 48 h back only, so the answer equals what the service gave at the time if readings start that far before `since`; the correction is not recomputed
- `php artisan forecast:backfill-base` asks a week at a time, with three days of readings before it, and fills in only forecasts made by the model now running

```
POST /light-correction
{"longitude": 13.40, "since": 1789596000,
 "readings": [{"timestamp": 1790000000, "temperature": 9.7, "humidity": 76.0, "pressure": 976.6,
               "illuminance": 1830.5, "received_at": 1790000031}, ...]}
```

- Readings as for `/correction` plus `illuminance` (lx, null without) and `received_at` (when the server stored it; default `timestamp`)

```
{"model": "2026-09-24T08:40:43.136429+00:00", "version": "light-v1",
 "profile": {"day": "2026-10-03", "values": [null, ..., 1830.5, ...]},
 "targets": {"T_1h": {"intercept": ..., "coefficients": {...}, "widen": ...}, ...}}
```

- `profile`: the newest reading's local day and its 48 half-hour references in lx

```
POST /light-forecast
{"longitude": 13.40, "experiment": {...}, "readings": [...]}
```

- `experiment`: the `/light-correction` answer as received; another model or version, or a profile for another local day than the newest reading's, gets 409

```
{"issued_at": 1790000000, "model": "2026-09-24T08:40:43.136429+00:00", "version": "light-v1",
 "horizons": [{"hours": 1, "temperature": {"low": 12.4, "mid": 13.6, "high": 15.1}}, ...]}
```

- Only fitted horizons
- `php artisan forecast:backfill-light <Y-m-d>` replays both calls for forecasts stored without the experiment, each from the readings the server held at the time

## Deploying

- `Dockerfile` builds from this directory: dependencies into a virtualenv with uv, then Python, the virtualenv and the five service files; about 550 MB
- The model is not in the image: mounted at `/models`
- No domain or published port; Laravel reaches it on the internal Docker network
- New model: train, check `evaluate_balcony.py`, copy `forecast.joblib` over the mounted one, add a changelog entry
- First deploy of a service that returns `base`: run `php artisan forecast:backfill-base` in the app container before a new model goes on (only the model that made a forecast can recompute its base)
