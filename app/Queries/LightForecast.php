<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * @phpstan-import-type LightIssued from ForecastService
 * @phpstan-import-type LightFitted from ForecastService
 * @phpstan-import-type Issued from ForecastService
 * @phpstan-import-type LitReading from ServiceReadings
 * @phpstan-import-type Horizon from Forecast
 */
final readonly class LightForecast
{
    /** An experiment is issued only this close to its forecast's window. */
    public const int MAX_LAG_SECONDS = 2 * ChartWindow::STEP_SECONDS;

    public function __construct(private ForecastService $service, private int $sensorId, private ?int $since) {}

    /**
     * @param  Issued  $shown
     * @param  list<LitReading>  $readings  the window the shown forecast was issued from
     * @return LightIssued|null
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function beside(array $shown, array $readings): ?array
    {
        if (abs(now()->getTimestamp() - $shown['issued_at']) > self::MAX_LAG_SECONDS || ! self::isLit($readings)) {
            return null;
        }

        return CachedFit::lightCorrection(
            $this->sensorId,
            $this->since,
            LocalTime::of($readings[array_key_last($readings)]['timestamp'])->isoDate(),
            fn (): array => $this->fit(new ServiceReadings($this->sensorId)->recent((int) config('forecast.history_days') * 86400, withLight: true)),
        )->issue(fn (array $fitted): array => $this->issue($shown, $readings, $fitted));
    }

    /**
     * @param  list<LitReading>  $history
     * @return LightFitted
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function fit(array $history): array
    {
        return $this->service->lightCorrection(array_filter([
            'longitude' => config('forecast.longitude'),
            'readings' => $history,
            'since' => $this->since,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  array{issued_at: int, model: string}  $shown
     * @param  list<LitReading>  $readings
     * @param  LightFitted  $fitted
     * @return LightIssued
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function issue(array $shown, array $readings, array $fitted): array
    {
        $experiment = $this->service->lightForecast([
            'longitude' => config('forecast.longitude'),
            'readings' => $readings,
            'experiment' => [...$fitted, 'targets' => (object) $fitted['targets']],
        ]);

        if ($experiment['issued_at'] !== $shown['issued_at'] || $experiment['model'] !== $shown['model']) {
            throw new UnexpectedValueException('Light experiment does not match the issued forecast');
        }

        return $experiment;
    }

    /**
     * @param  list<Horizon>  $horizons
     * @param  LightIssued  $experiment
     * @return list<Horizon>
     */
    public static function onto(array $horizons, array $experiment): array
    {
        $bands = array_column($experiment['horizons'], 'temperature', 'hours');

        return array_map(
            fn (array $horizon): array => isset($bands[$horizon['hours']])
                ? [...$horizon, 'experiment' => ['version' => $experiment['version'], 'temperature' => $bands[$horizon['hours']]]]
                : $horizon,
            $horizons,
        );
    }

    /**
     * @param  list<LitReading>  $readings
     *
     * @phpstan-assert-if-true non-empty-list<LitReading> $readings
     */
    public static function isLit(array $readings): bool
    {
        return array_filter($readings, fn (array $reading): bool => $reading['illuminance'] !== null) !== [];
    }
}
