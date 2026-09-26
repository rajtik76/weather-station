# Forecast model changelog

One entry per model bundle that goes on the server, headed by its
`trained_at` - the value the service reports as `model` and every row of
`forecasts` stores, so a forecast can be traced to the entry that made it.
`Data` is what it was trained on, `Server` the first app release whose
service and dashboard read it.
Scores are on 2025 at the ČHMÚ stations held out from training (see the
[README](README.md#results)), mean absolute error 6 h ahead against
persistence unless stated.

A change to the station correction's logic (`correction.py`) gets an entry
too, headed `Correction <n>` - the `CORRECTION_VERSION` the service reports
as `correction` and every row of `forecasts` stores; the rows from before it
was kept are version 1. Its scores are on the balcony, walked forward day by
day as production runs.

## Correction 2 - 2026-09-26

Model 2026-09-24T08:40:43Z · Server v3.10.0

- The daily shape of the station's error in solar-time bins, twenty minutes
  from 5 to 12 h and an hour elsewhere, instead of two harmonics of the day.
  The sun warms the shield within minutes on a morning; two harmonics
  smeared that warming into the small hours and the afternoon.
- Fitted on readings from 17 September 2026, after the shield went up
  (`FORECAST_HISTORY_SINCE`), scored on 20 to 26 September: temperature 1 h
  ahead 0.89 °C (correction 1 0.99, no correction 0.92); between 6 and 11 h,
  2 h ahead 1.74 °C (2.25), 3 h ahead 2.03 °C (2.45), 6 h ahead 1.92 °C
  (2.36).
- Still adds warmth on a morning the shield stays in shade: from temperature
  alone it cannot tell the two kinds of morning apart.

## 2026-09-24T08:40:43Z

Data ČHMÚ 10-minute, 2018-2024, 32 stations below 700 m · Server v3.4.0

- First model. Quantile models (10/50/90 %) for temperature, humidity and
  pressure, one to six hours; a classifier for rain within the next n hours.
- Inputs: the station's temperature, humidity and pressure history over 48
  hours, the unsettledness of the last three hours, rain in the last one and
  three hours (hidden on 30 % of the training rows), solar time and season.
- Temperature 1.38 °C (persistence 3.66), humidity 6.4 % (14.4), pressure
  0.85 hPa (1.36); the 10-90 % range held 76-81 %. Rain Brier 0.103 against
  0.148 for climatology, onset AUC 0.79.
- Missed the rain of 24 September 2026 at the balcony, 1-4 % beforehand.
