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
- Per horizon, skill by day: as shown vs base model on the same hours; the gap is what the station has taught the shown forecast
- Day a new model or correction version took over is marked
- Range width
- Mean rain chance given when it rained and when it did not
- Chart by hour of the day of how much warmer or colder the station read than forecast (morning sun on the shield reads warmer), shown and base on the same forecasts, for yesterday, the last 7 days or the last 30 days, by the hour each forecast was for
- Its default, today, draws the readings since local midnight against the forecast issued one horizon before each ten-minute slot, the shown range as a band; it grows with every reload

## Light experiment

Two more temperature forecasts learnt from the station alone ([`forecast/README.md`](../forecast/README.md#light-experiment)): light-v5 moves the base model by its misses on the balcony by day, with the sun on the shield read from the temperature swing within each window; light-v6 scales that move by the sky the VEML7700 reads. Both enter the model race below.

- After the forecast is stored, `ForecastWeather` asks `POST /light-forecast`; every version it answers goes into `candidates` under each horizon, the newest also into `experiment` (`version`, `temperature`)
- Its fit comes from `POST /light-correction` once per local day of the newest reading, from the 60 days through that reading with `since` from `FORECAST_HISTORY_SINCE`, and is cached per sensor
- A failure is reported and pauses the experiment for an hour; the forecast is already stored and the race picks among the entrants it has
- The forecast page adds the newest version as one more line to skill by day, range width and the hour-of-day chart, scored on its own forecasts; tooltips give each line's count. By hour it shows only in a period whose first forecast already carried it, today always
- `php artisan forecast:backfill-light <Y-m-d>` replays it on forecasts stored without its current version, from the readings the server held when each was issued (`created_at`); unlike the weather model, nothing later leaks in. An older version is replaced where the current one is issued, a forecast it cannot issue keeps the older one; the page scores the latest version only. A forecast stored before the race gets its shown band as the `correction` candidate
- Sample data: `LightForecastSeeder` adds a synthetic `demo-light-v1` curve, flagged `synthetic`; the page labels it a preview

## Model race

Picks the shown temperature for each part of the day from the model that has forecast it best lately.

- Entrants: `base`, `correction` (the station correction, shown before the race), `light-v5` and `light-v6` (`App\ValueObject\RaceEntrants`); the stored forecast keeps every entrant's band, `base` under `base`, the others under `candidates`
- Points per local day and part of the day the target falls in, night 22-6 h, morning 6-12 h, afternoon and evening 12-22 h (`App\Enums\RaceBlock`): each entrant's mean absolute miss of its median in °C, on the targets every entrant forecast (`App\Queries\ModelRace`)
- Standings: points summed over the 14 local days before today, worked out once a day after midnight and cached per sensor (`CachedModelRace`); the fewest lead, a tie goes to the simpler model in the order above (at night light-v5 and light-v6 answer the base band), a part of the day without points keeps the correction
- Every new forecast shows per horizon the band of the leader of its target's part of the day, or of the next entrant when the leader did not answer it; `shown_by` names it. Humidity and rain stay the correction's
- A stored forecast keeps what it showed; the race never re-picks history
- The forecast page charts every entrant's points by day for the chosen part of the day, lists the standings with the days each won, and names each hour of the current forecast by the model shown on the horizon nearest to it
