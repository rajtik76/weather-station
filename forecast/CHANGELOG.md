# Forecast model changelog

- One entry per model bundle on the server, headed by its `trained_at` (reported as `model`, stored in every `forecasts` row)
- `Data` = training data, `Server` = first app release whose service and dashboard read it
- Scores: 2025, held-out ČHMÚ stations ([README](README.md#results)), mean absolute error 6 h ahead vs persistence unless stated
- A change to the correction's logic (`correction.py`) gets an entry `Correction <n>` (`CORRECTION_VERSION`, reported as `correction`, stored per row; rows before it was kept are version 1); scores on the balcony, walked forward day by day

## Correction 3 - 2026-10-01

Model 2026-09-24T08:40:43Z · Server v4.4.0

- Same regression and bins as correction 2, fitted once a day by `POST /correction` instead of on every forecast; Laravel caches the coefficients and sends them with each forecast
- Forecasts once an hour from the last 56 hours instead of every ten minutes from 60 days
- Up to a day behind the newest verified misses; the `error_same` and `error_1h` inputs are still read at issue time

## Correction 2 - 2026-09-26

Model 2026-09-24T08:40:43Z · Server v3.10.0

- Daily error shape in solar-time bins (20 minutes from 5 to 12 h, an hour elsewhere) instead of two harmonics
- Fitted on readings from 17 September 2026 (`FORECAST_HISTORY_SINCE`), scored 20 to 26 September: temperature 1 h ahead 0.89 °C (correction 1: 0.99, none: 0.92); 6 to 11 h: 2 h ahead 1.74 °C (2.25), 3 h 2.03 °C (2.45), 6 h 1.92 °C (2.36)
- Still adds warmth on a morning the shield stays in shade
- Server v4.0.5: a sparse history no longer fails the request; a target without a verified row stays uncorrected, `corrected` is true only when every target was corrected; version stays 2

## 2026-09-24T08:40:43Z

Data ČHMÚ 10-minute, 2018-2024, 32 stations below 700 m · Server v3.4.0

- First model: quantile models (10/50/90 %) for temperature, humidity, pressure, 1 to 6 h; classifier for rain within the next n hours
- Inputs: 48 h history of temperature, humidity, pressure; unsettledness of the last 3 h; rain in the last 1 h and 3 h (hidden on 30 % of training rows); solar time and season
- Temperature 1.38 °C (persistence 3.66), humidity 6.4 % (14.4), pressure 0.85 hPa (1.36); 10-90 % range held 76-81 %; rain Brier 0.103 (climatology 0.148), onset AUC 0.79
- Missed the rain of 24 September 2026 at the balcony (1-4 % beforehand)
- Server v4.0.6: 1 h rain chance capped by the 2 h one (alone 50-100 % on dry balcony days, 94 % on the sunny morning of 1 October 2026); correction version stays 2; `base` keeps the classifier's own `rain_probability`
- Server v4.1.0: `POST /base` takes `"full": true` (each horizon as `POST /forecast` without the correction); used nightly for the Plzeň-Mikulka reference
