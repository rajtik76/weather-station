# Forecast scoring

The headline verdict (skill, mean miss, in range over 30 days) is in the [README](../README.md).

## Stored

- One forecast after every upload (the next upload replaces a failed one): the server sends the forecast service the last 56 hours with the station correction and stores the answer
- The correction is fitted on the last 60 days once a day and cached per sensor; a correction the service calls stale (another model or correction version, `409`) is refitted at once
- Temperature as a range 1 to 6 h ahead, chance of rain, and the same temperature from the base model (before the station correction)
- `App\Queries\ForecastAccuracy` reads and pairs, `App\ValueObject\ForecastScore` does the arithmetic, `ModelName` names a model

## Weather model

Stored for later use, neither scored nor shown: it forecasts air temperature, the station forecasts its own reading with the sun on the shield.

- `ForecastWeather` fetches DWD ICON (`icon_seamless`) from Open-Meteo for the station's coordinates and elevation with every forecast and stores it as `nwp.temperature` (°C) under each horizon
- Hourly values interpolated to the middle of the scored ten-minute window
- Skipped for a forecast issued more than 20 min ago (late batch): a run fetched now would know more than the forecast did
- A failed fetch is reported; the forecast is stored without it

## Forecast page

- Same scoring for every hour ahead
- Per horizon, skill by day: as shown vs base model on the same hours; the gap is what the correction learnt from the station's misses
- Day a new model or correction version took over is marked
- Range width
- Mean rain chance given when it rained and when it did not
- Chart by hour of the day of how much warmer or colder the station read than forecast (morning sun on the shield reads warmer), shown and base on the same forecasts, for yesterday, the last 7 days or the last 30 days, by the hour each forecast was for
- Its default, today, draws the readings since local midnight against the forecast issued one horizon before each ten-minute slot, the shown range as a band; it grows with every reload

## Light experiment

A second temperature forecast: the base model moved by its misses on the balcony by day, learnt from the station alone with the sun on the shield read from the temperature swing within each window ([`forecast/README.md`](../forecast/README.md#light-experiment)). It runs beside the shown forecast and changes nothing on it.

- After the shown forecast is stored, `ForecastWeather` asks `POST /light-forecast` and stores the answer as `experiment` (`version`, `temperature`) under each horizon it covers
- Its fit comes from `POST /light-correction` once per local day of the newest reading, from the 60 days through that reading with `since` from `FORECAST_HISTORY_SINCE`, and is cached per sensor
- A failure is reported and pauses the experiment for an hour; the forecast is already stored
- The forecast page adds it as one more line to skill by day, range width and the hour-of-day chart, scored on its own forecasts; shown and base stay exactly as without it, tooltips give each line's count. By hour it shows only in a period whose first forecast already carried it, today always
- `php artisan forecast:backfill-light <Y-m-d>` replays it on forecasts stored without its current version, from the readings the server held when each was issued (`created_at`); unlike the weather model, nothing later leaks in. An older version is replaced where the current one is issued, a forecast it cannot issue keeps the older one; the page scores the latest version only
- Sample data: `LightForecastSeeder` adds a synthetic `demo-light-v1` curve, flagged `synthetic`; the page labels it a preview
