# Forecast scoring

The headline verdict (skill, mean miss, in range over 30 days) is in the [README](../README.md).

## Stored

- After every upload the server sends the forecast service the station's last 60 days and stores the answer
- Temperature as a range 1 to 6 h ahead, chance of rain, and the same temperature from the base model (before the station correction)
- `App\Queries\ForecastAccuracy` reads and pairs, `App\ValueObject\ForecastScore` does the arithmetic, `ModelName` names a model

## Forecast page

- Same scoring for every hour ahead
- Per horizon, skill by day: as shown vs base model on the same hours; the gap is what the correction learnt from the station's misses
- Day a new model or correction version took over is marked
- Range width
- Mean rain chance given when it rained and when it did not
- Chart by hour of the day of how much warmer or colder the station read than forecast (morning sun on the shield reads warmer)

## Reference

- Nightly, the same model without station correction forecasts the previous day at ČHMÚ Plzeň-Mikulka (3.5 km away, 10-minute record) and is scored the same way
- Both lines dropping: hard weather; only the balcony's: the balcony
- `php artisan forecast:reference`, scheduled 01:00 UTC
- The station is stored as a sensor of its own and kept out of the picker
