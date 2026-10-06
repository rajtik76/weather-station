<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\CachedFit;
use App\Queries\ForecastService;
use App\Queries\LightForecast;
use App\Queries\OpenMeteoForecast;
use App\Queries\ServiceReadings;
use App\ValueObject\ChartWindow;
use App\ValueObject\HistorySince;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * After every upload, asks the forecast service (forecast/serve.py) for the next six hours with the station correction and stores the answer with the NWP temperature beside it.
 *
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Issued from ForecastService
 * @phpstan-import-type Fitted from ForecastService
 * @phpstan-import-type Reading from ServiceReadings
 * @phpstan-import-type LitReading from ServiceReadings
 */
class ForecastWeather
{
    use Dispatchable;

    /** Older forecasts get no NWP: a model run fetched now would know more than the forecast did. */
    private const int NWP_LAG_SECONDS = 2 * ChartWindow::STEP_SECONDS;

    private const int EXPERIMENT_PAUSE_MINUTES = 60;

    public function __construct(public Sensor $sensor) {}

    public function handle(): void
    {
        $readings = new ServiceReadings($this->sensor->id)->recent((int) config('forecast.lookback_hours') * 3600, withLight: true);

        if ($readings === [] || $this->isIssuedInWindowOf($readings[array_key_last($readings)]['timestamp'])) {
            return;
        }

        $service = ForecastService::fromConfig();
        $since = HistorySince::fromConfig();
        $correction = CachedFit::stationCorrection($this->sensor->id, $since, fn (): array => $service->correction(array_filter([
            'longitude' => config('forecast.longitude'),
            'readings' => new ServiceReadings($this->sensor->id)->recent((int) config('forecast.history_days') * 86400),
            'since' => $since,
        ], fn (mixed $value): bool => $value !== null)));

        try {
            $forecast = $correction->issue(fn (array $fitted): array => $this->forecast($service, $this->withoutLight($readings), $fitted));
        } catch (ConnectionException|RequestException $exception) {
            // Retried by the next upload; the upload must not fail.
            report($exception);

            return;
        }

        $stored = Forecast::query()->updateOrCreate(
            ['sensor_id' => $this->sensor->id, 'issued_at' => $forecast['issued_at']],
            [
                'model' => $forecast['model'],
                'corrected' => $forecast['corrected'],
                'correction' => $forecast['correction'] ?? null,
                'data' => $this->withNwp($forecast['issued_at'], $forecast['horizons']),
            ],
        );

        $this->attachExperiment($stored, $service, $forecast, $readings);
    }

    private function isIssuedInWindowOf(int $timestamp): bool
    {
        return Forecast::query()
            ->where('sensor_id', $this->sensor->id)
            ->where('issued_at', '>=', intdiv($timestamp, Forecast::INTERVAL_SECONDS) * Forecast::INTERVAL_SECONDS)
            ->exists();
    }

    /**
     * Runs after the forecast is stored; a failure pauses the experiment for EXPERIMENT_PAUSE_MINUTES.
     *
     * @param  Issued  $forecast
     * @param  list<LitReading>  $readings
     */
    private function attachExperiment(Forecast $stored, ForecastService $service, array $forecast, array $readings): void
    {
        $paused = "light-experiment-paused:{$this->sensor->id}";

        if (Cache::has($paused)) {
            return;
        }

        try {
            $experiment = new LightForecast($service, $this->sensor->id)->beside($forecast, $readings);
        } catch (Throwable $exception) {
            report($exception);
            Cache::put($paused, true, now()->addMinutes(self::EXPERIMENT_PAUSE_MINUTES));

            return;
        }

        if ($experiment !== null) {
            $stored->update(['data' => LightForecast::onto($stored->data, $experiment)]);
        }
    }

    /**
     * @param  list<LitReading>  $readings
     * @return list<Reading>
     */
    private function withoutLight(array $readings): array
    {
        return array_map(fn (array $reading): array => [
            'timestamp' => $reading['timestamp'],
            'temperature' => $reading['temperature'],
            'humidity' => $reading['humidity'],
            'pressure' => $reading['pressure'],
        ], $readings);
    }

    /**
     * Without targets (short history) no correction is sent: PHP would encode the empty object as a list.
     *
     * @param  list<Reading>  $readings
     * @param  Fitted  $correction
     * @return Issued
     */
    private function forecast(ForecastService $service, array $readings, array $correction): array
    {
        return $service->forecast([
            'longitude' => config('forecast.longitude'),
            'readings' => $readings,
            ...($correction['targets'] === [] ? [] : ['correction' => $correction]),
        ]);
    }

    /**
     * @param  list<Horizon>  $horizons
     * @return list<Horizon>
     */
    private function withNwp(int $issuedAt, array $horizons): array
    {
        $nwp = OpenMeteoForecast::fromConfig();

        if (! $nwp instanceof OpenMeteoForecast || $horizons === [] || now()->getTimestamp() - $issuedAt > self::NWP_LAG_SECONDS) {
            return $horizons;
        }

        try {
            $temperatures = $nwp->temperatures($issuedAt, array_column($horizons, 'hours'));
        } catch (ConnectionException|RequestException $exception) {
            // The forecast is stored without it.
            report($exception);

            return $horizons;
        }

        return array_map(
            fn (array $horizon): array => isset($temperatures[$horizon['hours']])
                ? [...$horizon, 'nwp' => ['temperature' => $temperatures[$horizon['hours']]]]
                : $horizon,
            $horizons,
        );
    }
}
