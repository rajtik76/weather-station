# Forecast model changelog

One entry per model bundle that goes on the server, headed by its
`trained_at` - the value the service reports as `model` and every row of
`forecasts` stores, so a forecast can be traced to the entry that made it.
`Data` is what it was trained on, `Server` the first app release whose
service and dashboard read it.
Scores are on 2025 at the ČHMÚ stations held out from training (see the
[README](README.md#results)), mean absolute error 6 h ahead against
persistence unless stated.

## 2026-09-24T08:40:43Z - not deployed yet

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
