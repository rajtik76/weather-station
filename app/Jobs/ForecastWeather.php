<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\CachedStationCorrection;
use App\Queries\ForecastService;
use App\Queries\OpenMeteoForecast;
use App\Queries\ServiceReadings;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

/**
 * Once an hour, asks the forecast service (forecast/serve.py) for the next six hours with the station correction and stores the answer with the NWP temperature beside it.
 *
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Issued from ForecastService
 * @phpstan-import-type Fitted from ForecastService
 */
class ForecastWeather
{
    use Dispatchable;

    /** Older forecasts get no NWP: a model run fetched now would know more than the forecast did. */
    private const int NWP_LAG_SECONDS = 2 * ChartWindow::STEP_SECONDS;

    public function __construct(public Sensor $sensor) {}

    public function handle(): void
    {
        $readings = new ServiceReadings($this->sensor->id)->recent((int) config('forecast.lookback_hours') * 3600);

        if ($readings === [] || $this->isIssuedInHourOf($readings[array_key_last($readings)]['timestamp'])) {
            return;
        }

        $service = ForecastService::fromConfig();
        $since = $this->correctionSince();

        try {
            $forecast = $this->issue(
                $service,
                $readings,
                new CachedStationCorrection($this->sensor->id, $since),
                fn (): array => $service->correction(array_filter([
                    'longitude' => config('forecast.longitude'),
                    'readings' => new ServiceReadings($this->sensor->id)->recent((int) config('forecast.history_days') * 86400),
                    'since' => $since,
                ], fn (mixed $value): bool => $value !== null)),
            );
        } catch (ConnectionException|RequestException $exception) {
            // The next upload in the hour retries; the upload must not fail.
            report($exception);

            return;
        }

        Forecast::query()->updateOrCreate(
            ['sensor_id' => $this->sensor->id, 'issued_at' => $forecast['issued_at']],
            [
                'model' => $forecast['model'],
                'corrected' => $forecast['corrected'],
                'correction' => $forecast['correction'] ?? null,
                'data' => $this->withNwp($forecast['issued_at'], $forecast['horizons']),
            ],
        );
    }

    private function isIssuedInHourOf(int $timestamp): bool
    {
        return Forecast::query()
            ->where('sensor_id', $this->sensor->id)
            ->where('issued_at', '>=', intdiv($timestamp, Forecast::INTERVAL_SECONDS) * Forecast::INTERVAL_SECONDS)
            ->exists();
    }

    /**
     * A correction fitted for another model or correction version is refitted once.
     *
     * @param  list<array{timestamp: int, temperature: float, humidity: float, pressure: float}>  $readings
     * @param  Closure(): Fitted  $fit
     * @return Issued
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function issue(ForecastService $service, array $readings, CachedStationCorrection $correction, Closure $fit): array
    {
        try {
            return $this->forecast($service, $readings, $correction->current($fit));
        } catch (RequestException $exception) {
            if (! $exception->response->conflict()) {
                throw $exception;
            }

            return $this->forecast($service, $readings, $correction->refit($fit));
        }
    }

    /**
     * Without targets (short history) no correction is sent: PHP would encode the empty object as a list.
     *
     * @param  list<array{timestamp: int, temperature: float, humidity: float, pressure: float}>  $readings
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

    /** Local midnight of history_since; an unparsable date is reported and ignored. */
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
