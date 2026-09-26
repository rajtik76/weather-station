<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\ServiceReadings;
use App\ValueObject\LocalTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Asks the forecast service (forecast/serve.py) for the next six hours from the
 * sensor's recent history and stores the answer, the forecast before the
 * station correction included under each horizon's `base`. The service
 * keeps no state, so every run sends the whole window it learns the station
 * correction from.
 *
 * @phpstan-import-type Horizon from Forecast
 */
class ForecastWeather
{
    use Dispatchable;

    public function __construct(public Sensor $sensor) {}

    public function handle(): void
    {
        $readings = new ServiceReadings($this->sensor->id)->between(now()->subDays((int) config('forecast.history_days'))->getTimestamp());

        if ($readings === []) {
            return;
        }

        try {
            $response = Http::timeout(30)
                ->post(rtrim((string) config('forecast.url'), '/').'/forecast', array_filter([
                    'longitude' => config('forecast.longitude'),
                    'readings' => $readings,
                    'since' => $this->correctionSince(),
                ], fn (mixed $value): bool => $value !== null))
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            // A missed forecast is replaced ten minutes later; the upload must not fail.
            report($exception);

            return;
        }

        /** @var array{issued_at: int, model: string, corrected: bool, correction?: int, horizons: list<Horizon>} $forecast */
        $forecast = $response->json();

        Forecast::query()->updateOrCreate(
            ['sensor_id' => $this->sensor->id, 'issued_at' => $forecast['issued_at']],
            [
                'model' => $forecast['model'],
                'corrected' => $forecast['corrected'],
                'correction' => $forecast['correction'] ?? null,
                'data' => $forecast['horizons'],
            ],
        );
    }

    /**
     * Local midnight of history_since, from which on the station correction
     * learns: readings from before a change at the station would teach it the
     * wrong thing. Only the correction - the base models still get the whole
     * history, as they need 48 hours of it behind every forecast. A date that
     * does not parse is reported and left out, so the forecasts go on.
     */
    private function correctionSince(): ?int
    {
        $since = config('forecast.history_since');

        if (! is_string($since) || $since === '') {
            return null;
        }

        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $since, new DateTimeZone(LocalTime::TIMEZONE));

        // createFromFormat() rolls 2026-17-09 over into 2027; only a date that reads back the same is one.
        if ($midnight === false || $midnight->format('Y-m-d') !== $since) {
            report(new InvalidArgumentException("FORECAST_HISTORY_SINCE is not a Y-m-d date: {$since}"));

            return null;
        }

        return $midnight->getTimestamp();
    }
}
