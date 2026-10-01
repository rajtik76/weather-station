<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\ForecastService;
use App\Queries\OpenMeteoForecast;
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
 * Asks the forecast service (forecast/serve.py) for the next six hours and stores the answer with the NWP temperature beside it.
 *
 * @phpstan-import-type Horizon from Forecast
 */
class ForecastWeather
{
    use Dispatchable;

    /** Tolerated clock lead; a row stamped further ahead is a clock fault and would pin every forecast to a future issued_at. */
    private const int AHEAD_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** Older forecasts get no NWP: a model run fetched now would know more than the forecast did. */
    private const int NWP_LAG_SECONDS = 2 * ChartWindow::STEP_SECONDS;

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
                'data' => $this->withNwp($forecast['issued_at'], $forecast['horizons']),
            ],
        );
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

    /** Local midnight of history_since, from which the station correction learns; an unparsable date is reported and ignored. */
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
