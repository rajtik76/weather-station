<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\ForecastService;
use App\Queries\ServiceReadings;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

/**
 * Asks the forecast service (forecast/serve.py) for the next six hours from the
 * sensor's recent history and stores the answer, the forecast before the
 * station correction included under each horizon's `base`. The service
 * keeps no state, so every run sends the whole window it learns the station
 * correction from.
 */
class ForecastWeather
{
    use Dispatchable;

    /** Tolerated clock lead; a row stamped further ahead is a clock fault and would pin every forecast to a future issued_at. */
    private const int AHEAD_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    public function __construct(public Sensor $sensor) {}

    public function handle(): void
    {
        $readings = new ServiceReadings($this->sensor->id)->between(
            now()->subDays((int) config('forecast.history_days'))->getTimestamp(),
            now()->getTimestamp() + self::AHEAD_SECONDS,
        );

        if ($readings === []) {
            return;
        }

        try {
            $forecast = ForecastService::fromConfig()->forecast(array_filter([
                'longitude' => config('forecast.longitude'),
                'readings' => $readings,
                'since' => $this->correctionSince(),
            ], fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException|RequestException $exception) {
            // A missed forecast is replaced ten minutes later; the upload must not fail.
            report($exception);

            return;
        }

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
     * @param  list<Horizon>  $horizons
     * @return list<Horizon>
     */
    private function correctionSince(): ?int
    {
        $since = config('forecast.history_since');

        if (! is_string($since) || $since === '') {
            return null;
        }

        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $since, new DateTimeZone(LocalTime::TIMEZONE));

        // createFromFormat() rolls 2026-17-09 over into 2027; require a round trip.
        if ($midnight === false || $midnight->format('Y-m-d') !== $since) {
            report(new InvalidArgumentException("FORECAST_HISTORY_SINCE is not a Y-m-d date: {$since}"));

            return null;
        }

        return $midnight->getTimestamp();
    }
}
