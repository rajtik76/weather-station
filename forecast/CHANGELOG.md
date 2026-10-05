# Forecast model changelog

- One entry per model bundle on the server, headed by its `trained_at` (reported as `model`, stored in every `forecasts` row)
- `Data` = training data, `Server` = first app release whose service and dashboard read it
- Scores: 2025, held-out ČHMÚ stations ([README](README.md#results)), mean absolute error 6 h ahead vs persistence unless stated
- A change to the correction's logic (`correction.py`) gets an entry `Correction <n>` (`CORRECTION_VERSION`, reported as `correction`, stored per row; rows before it was kept are version 1); scores on the balcony, walked forward day by day

## Light experiment light-v2 - 2026-10-05

Model 2026-09-24T08:40:43Z · Server v4.11.0

- Fitted on the gain measured at the target instead of at issue; rows without light or a reference do not teach
- Issued with the expected gain: the gain now fading into the target half hour's 14-day mean by 6 h ahead, the mean alone in the dark (light-v1 assumed full sun before dawn, so 8 and 9 h matched shown); no halving from 4 h
- Needs three days of lit history: first fit 5 October 2026
- Walked forward on the balcony, fitted at local midnight, 5 October 2026 (overcast, fog from 9 h), temperature vs shown and light-v1: 3 h 1.18 °C (2.36, 2.18), 6 h 1.72 °C (3.81, 3.26); mornings 7 to 11 h, 3 h 1.69 °C (4.39, 4.01), base 1.60; one day, no clear morning yet

## Light experiment light-v1 - 2026-10-03

Model 2026-09-24T08:40:43Z · Server v4.6.0

- `light_correction.py`, beside correction 3, temperature only; does not change the shown forecast
- Solar inputs scaled by a light gain: smoothed lux over the 90th percentile of the same half hour in the 14 days before (only readings that had arrived by then), capped at 1; solar inputs halved from 4 h ahead
- Walked forward on the balcony, fitted at local midnight, 1 to 3 October 2026, temperature vs shown: 1 h 0.72 °C (0.82), 3 h 1.36 °C (1.47), 6 h 1.48 °C (1.72); without the light gain 1 to 3 h equal shown, so that gain is the lux; part of 4 to 6 h is the halving alone
- 1 October no gain (half a day of light behind its reference); three days only

## Correction 3 - 2026-10-01

Model 2026-09-24T08:40:43Z · Server v4.4.0

- Same regression and bins as correction 2, fitted once a day by `POST /correction` instead of on every forecast; Laravel caches the coefficients and sends them with each forecast
- Forecasts once an hour from the last 56 hours instead of every ten minutes from 60 days
- Up to a day behind the newest verified misses; the `error_same` and `error_1h` inputs are still read at issue time
- Server v4.5.0: forecasts after every upload again, still from 56 hours; version stays 3

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
- Server v4.4.0: `"full"` removed with the Plzeň-Mikulka reference
