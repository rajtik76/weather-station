<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ChartWindow;
use App\ValueObject\HistorySince;
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

    public function __construct(private ForecastService $service, private int $sensorId) {}

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
        if ($readings === [] || abs(now()->getTimestamp() - $shown['issued_at']) > self::MAX_LAG_SECONDS) {
            return null;
        }

        $newest = $readings[array_key_last($readings)]['timestamp'];
        $since = HistorySince::fromConfig();

        return CachedFit::lightCorrection(
            $this->sensorId,
            LocalTime::of($newest)->isoDate(),
            $since,
            fn (): array => $this->fit(
                new ServiceReadings($this->sensorId)->between($newest - (int) config('forecast.history_days') * 86400, $newest, withLight: true),
                $since,
            ),
        )->issue(fn (array $fitted): array => $this->issue($shown, $readings, $fitted));
    }

    /**
     * @param  list<LitReading>  $history  through the newest reading of the window it will issue from
     * @return LightFitted
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function fit(array $history, ?int $since): array
    {
        return $this->service->lightCorrection(array_filter([
            'longitude' => config('forecast.longitude'),
            'readings' => $history,
            'since' => $since,
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
            'experiment' => $fitted,
        ]);

        if ($experiment['issued_at'] !== $shown['issued_at'] || $experiment['model'] !== $shown['model']) {
            throw new UnexpectedValueException('Light experiment does not match the issued forecast');
        }

        return $experiment;
    }

    /**
     * @param  LightIssued  $experiment
     */
    public static function answersNothing(array $experiment): bool
    {
        return ($experiment['versions'][$experiment['version']] ?? []) === [];
    }

    /**
     * The newest version is a horizon's experiment and every version that answers it one of its race candidates;
     * a horizon a version does not answer loses what that version held.
     *
     * @param  list<Horizon>  $horizons
     * @param  LightIssued  $experiment
     * @return list<Horizon>
     */
    public static function onto(array $horizons, array $experiment): array
    {
        $bands = array_map(fn (array $answered): array => array_column($answered, 'temperature', 'hours'), $experiment['versions']);
        $newest = $bands[$experiment['version']] ?? [];

        return array_map(
            function (array $horizon) use ($bands, $newest, $experiment): array {
                $hours = $horizon['hours'];

                foreach ($bands as $version => $byHours) {
                    if (isset($byHours[$hours])) {
                        $horizon['candidates'][$version] = $byHours[$hours];
                    } else {
                        unset($horizon['candidates'][$version]);
                    }
                }

                if (isset($newest[$hours])) {
                    $horizon['experiment'] = ['version' => $experiment['version'], 'temperature' => $newest[$hours]];
                } else {
                    unset($horizon['experiment']);
                }

                return $horizon;
            },
            $horizons,
        );
    }
}
