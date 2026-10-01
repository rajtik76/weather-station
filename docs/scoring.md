# Forecast scoring

The headline verdict (skill, mean miss, in range over 30 days) is in the [README](../README.md).

## Stored

- One forecast an hour, issued from the first upload of the hour (a later upload retries a failed one): the server sends the forecast service the last 56 hours with the station correction and stores the answer
- The correction is fitted on the last 60 days once a day and cached per sensor; a correction the service calls stale (another model or correction version, `409`) is refitted at once
- Temperature as a range 1 to 6 h ahead, chance of rain, and the same temperature from the base model (before the station correction)
- `App\Queries\ForecastAccuracy` reads and pairs, `App\ValueObject\ForecastScore` does the arithmetic, `ModelName` names a model

## Weather model

- `ForecastWeather` fetches DWD ICON (`icon_seamless`) from Open-Meteo for the station's coordinates and elevation with every forecast and stores it as `nwp.temperature` (°C) under each horizon
- Hourly values interpolated to the middle of the scored ten-minute window
- Skipped for a forecast issued more than 20 min ago (late batch): a run fetched now would know more than the forecast did
- A failed fetch is reported; the forecast is stored without it
- `nwp` figures per horizon, on hours with the forecast, the naive guess and the model: `skill` (model vs naive), `versus` (forecast vs model), `error` (model), `shownError` (forecast), `naive`
- Not for the Mikulka reference: past model runs are not available as they were issued

## Forecast page

- Same scoring for every hour ahead
- Per horizon, skill by day: as shown vs base model on the same hours; the gap is what the correction learnt from the station's misses; the weather model's skill as a third line
- Verdict by horizon: weather model's skill vs naive and the forecast's skill vs the model
- Day a new model or correction version took over is marked
- Range width
- Mean rain chance given when it rained and when it did not
- Chart by hour of the day of how much warmer or colder the station read than forecast (morning sun on the shield reads warmer)

## Reference

- Nightly, the same model without station correction forecasts the previous day at ČHMÚ Plzeň-Mikulka (3.5 km away, 10-minute record) and is scored the same way
- Both lines dropping: hard weather; only the balcony's: the balcony
- `php artisan forecast:reference`, scheduled 01:00 UTC
- The station is stored as a sensor of its own and kept out of the picker
