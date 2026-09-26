<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\ServiceReadings;
use App\ValueObject\LocalTime;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

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
        $readings = new ServiceReadings($this->sensor->id)->between($this->historyFrom());

        if ($readings === []) {
            return;
        }

        try {
            $response = Http::timeout(30)
                ->post(rtrim((string) config('forecast.url'), '/').'/forecast', [
                    'longitude' => config('forecast.longitude'),
                    'readings' => $readings,
                ])
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
     * The last history_days, cut at history_since when it is set: readings
     * from before a change at the station would teach the correction the
     * wrong thing. By the station's stamp, like every measurement time.
     */
    private function historyFrom(): int
    {
        $from = now()->subDays((int) config('forecast.history_days'))->getTimestamp();
        $since = config('forecast.history_since');

        if (! is_string($since) || $since === '') {
            return $from;
        }

        return max($from, Date::parse($since, LocalTime::TIMEZONE)->startOfDay()->getTimestamp());
    }
}
