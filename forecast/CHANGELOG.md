# Forecast model changelog

- One entry per model bundle on the server, headed by its `trained_at` (reported as `model`, stored in every `forecasts` row)
- `Data` = training data, `Server` = first app release whose service and dashboard read it
- Scores: 2025, held-out ČHMÚ stations ([README](README.md#results)), mean absolute error 6 h ahead vs persistence unless stated
- A change to the correction's logic (`correction.py`) gets an entry `Correction <n>` (`CORRECTION_VERSION`, reported as `correction`, stored per row; rows before it was kept are version 1); scores on the balcony, walked forward day by day

## Light experiment light-v6 - 2026-10-10

Model 2026-09-24T08:40:43Z · Server v4.17.0

- Beside light-v5, not instead of it: both enter the model race that now picks the shown temperature per part of the day ([`docs/scoring.md`](../docs/scoring.md#model-race)); light-v6 is the page's experiment line
- `sky_gate.py`: light-v5's shift times a factor per horizon and sky state. The sky from the VEML7700 alone: illuminance over the brightest reading at the same clock slot in the 14 days before the issue's day; in daylight (sun above 8°) the last hour, otherwise the daylight of the last 24 h; overcast below 0.45, clear above 0.8. Factor: the shift-weighted median of the miss over light-v5's out-of-fold shift, 0 to 1.5; a state without 40 training rows, or no light, keeps light-v5
- Band: light-v5's moved by the scaled shift, widened by its own out-of-fold misses
- Walked forward on the balcony, fitted at local midnight on the days before, temperature 1/2/3/4/5/6 h ahead:
    - 24 September to 10 October 2026: 0.69/0.99/1.24/1.39/1.56/1.62 °C (light-v5 0.69/1.00/1.26/1.45/1.61/1.69, shown 0.80/1.18/1.44/1.60/1.67/1.75, base 0.81/1.27/1.62/1.82/1.88/1.88)
    - 1 to 10 October, targets 7 to 17 h: 1.00/1.45/1.93/1.99/2.22/2.14 °C (light-v5 1.00/1.52/2.05/2.24/2.50/2.54, base 1.09/1.70/2.18/2.34/2.39/2.31)
- Overcast days gain most, mean over 1-6 h for targets 7 to 17 h: 9 October 1.29 °C (light-v5 2.00, base 1.22), 10 October 1.65 °C (2.37, 1.18), 5 October 1.79 °C (2.83, 1.61)
- Clear mornings forecast before sunrise are its weak spot: the night reads yesterday's sky; 6 October 2.49 °C (light-v5 1.74), 7 October 2.51 °C (1.65)
- Tried and dropped: illuminance and humidity straight into light-v5's trees (gained little), a sky state from a classifier trained on ČHMÚ Plzeň-Mikulka sunshine (79 % of windows right leaving each day out, no better as a gate than the illuminance ratio)

## Light experiment light-v5 - 2026-10-07

Model 2026-09-24T08:40:43Z · Server v4.15.0

- Replaces light-v4, whose heating model, fitted on six October mornings, lost to the base model at 1-2 h and 5-6 h
- `sun_correction.py`: per horizon a gradient-boosting model (absolute loss) of the base forecast's miss by day; inputs the temperature swing within each window (sun on the shield, reported since the shield went up), the sun's position at issue and target, the last 1 and 3 h change, the base model's change and its misses verified now; night stays the base
- Fitted once a day by `POST /light-correction` on every day from `FORECAST_HISTORY_SINCE` (17 September 2026), so also on the two weeks before the VEML7700; illuminance not an input (helped some days, hurt others with a week of light)
- Band: base band moved with the median, widened to 80 % by out-of-fold misses (four folds by local day); held 82-85 %
- Issued also from a window without light (light-v4 needed the VEML7700)
- The east facade by its measured normal, 71.8° (sun in front of it from -18.2° to 161.8°), so spring and summer morning sun counts too
- Walked forward on the balcony, fitted at local midnight on the days before, temperature 1/2/3/4/5/6 h ahead:
    - 24 September to 7 October 2026: 0.69/0.96/1.16/1.35/1.49/1.55 °C (shown 0.80/1.17/1.42/1.55/1.60/1.68, base 0.86/1.33/1.70/1.89/1.92/1.89)
    - 30 September to 7 October: 0.63/0.98/1.27/1.51/1.64/1.74 °C (shown 0.69/1.08/1.43/1.67/1.75/1.86, light-v4 0.80/1.21/1.52/1.72/1.92/2.01, base 0.73/1.18/1.55/1.76/1.83/1.89)
- Overcast mornings stay its weak spot: before sunrise it expects the sun most mornings had; 3 to 7 October (fog, low cloud) 4 to 6 h ahead it was level with base: 1.62/1.72/1.86 °C (base 1.60/1.72/1.83, light-v4 1.60/1.78/1.94, shown 1.68/1.79/1.96)
- Tried and dropped: illuminance, pressure tendency, night cooling and the night's humidity rise, yesterday's sun, one model for all horizons, recency weighting (each moved errors between days); quantile models for the band (held 59-77 %)

## Light experiment light-v4 - 2026-10-06

Model 2026-09-24T08:40:43Z · Server v4.14.0

- Replaces light-v3, which scaled solar-time bins by the light and learnt them from four dim mornings, so the VEML hardly moved it
- The shield's heating as a first-order model: a rate per sun azimuth (90-160°) for every 1000 lx above 500 lx, 16.9 % shed per 10 minutes (54 min); fitted offline against the air at ČHMÚ Plzeň-Mikulka and, before 10 h, Plzeň-Slovany, 30 September to 6 October 2026; balcony minus Mikulka with no sun averages 0.0 K
- The base model on the readings with the heating taken out, plus the heating carried to the target with the sky held at its gain now (0.5 in the dark)
- October mornings only: spring and summer sun rising north of 90° heats nothing in the model yet
- Walked forward on the balcony, fitted at local midnight, 2 to 6 October 2026 (the heating rates were fitted on these days); temperature for targets 7 to 12 h, mean over 1-6 h: 1.89 °C (base 1.84, shown 2.64, light-v3 1.89); 6 October, clear: 3.09 °C (base 3.89, light-v3 3.96, shown 1.82); whole day 1.31 °C (base 1.23, light-v3 1.22)
- Tried and dropped: sky held at the 14-day mean (mornings 2.14 °C); a clear-morning guess from the night's temperature, humidity and pressure, trained on 14 ČHMÚ stations 2018-2025 (AUC 0.82 half an hour before sunrise on 2024-2025); on the balcony it read nearly every night as clear and made the mornings 2.18 °C

## Light experiment light-v3 - 2026-10-05

Model 2026-09-24T08:40:43Z · Server v4.13.0

- light-v2 without the recent-error inputs (`error_same`, `error_1h`) from 4 h ahead; 1 to 3 h unchanged. Fitted on sunny mornings, their weights pushed overcast and foggy ones up
- Walked forward on the balcony, fitted at local midnight, 5 October 2026 (fog 8 to 9:40 h), temperature vs light-v2: 4 h 1.74 °C (1.66), 5 h 2.17 °C (2.21), 6 h 2.62 °C (2.73); mornings 7 to 12 h, 4 h 2.36 °C (2.42), 6 h 2.83 °C (2.95); in the fog 4 h 1.60 °C (1.83), 5 h 1.80 °C (2.07). Base lower at every horizon that day
- With 200 history rows instead of 432 also 3 and 4 October: 6 h 1.49 °C (1.67), mornings 6 h 1.65 °C (1.93)
- Tried and dropped: the base model's rise scaled by the missing light and by air near saturation, gain held while saturated. Within 0.05 °C or worse; on 3 October the same damp, dim start (1.4 K above dew point, under 300 lx at 8 h) warmed 6 °C by 10 h, so the station alone does not tell when fog lifts

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
