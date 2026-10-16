<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\TemperatureHistory;

/**
 * @phpstan-import-type TemperatureRow from TemperatureHistory
 * @phpstan-import-type CandidateRow from TemperatureHistory
 *
 * @phpstan-type Deviation array{mae: ?float, bias: ?float, min: ?float, max: ?float}
 * @phpstan-type Summary array{count: int, unavailable: int, measured: Deviation, estimated: Deviation}
 * @phpstan-type CountedDeviation array{count: int, mae: ?float, bias: ?float, min: ?float, max: ?float}
 * @phpstan-type CandidateSummary array{measured: CountedDeviation, estimated: CountedDeviation, candidate: CountedDeviation}
 */
final readonly class TemperatureComparison
{
    /**
     * @param  list<TemperatureRow>  $readings
     * @return Summary
     */
    public static function summary(array $readings): array
    {
        $scored = self::score($readings, ['measured', 'estimated']);

        return ['count' => $scored['count'], 'unavailable' => $scored['unavailable'], 'measured' => $scored['deviations']['measured'], 'estimated' => $scored['deviations']['estimated']];
    }

    /**
     * Each series on every observation where it has a value.
     *
     * @param  list<CandidateRow>  $readings
     * @return CandidateSummary
     */
    public static function candidate(array $readings): array
    {
        $summary = [];

        foreach (['measured', 'estimated', 'candidate'] as $series) {
            $differences = [];

            foreach ($readings as $reading) {
                if ($reading['reference'] !== null && $reading[$series] !== null) {
                    $differences[] = $reading[$series] - $reading['reference'];
                }
            }

            $summary[$series] = ['count' => count($differences), ...self::deviation($differences)];
        }

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $readings
     * @param  non-empty-list<string>  $series
     * @return array{count: int, unavailable: int, deviations: array<string, Deviation>}
     */
    private static function score(array $readings, array $series): array
    {
        $differences = array_fill_keys($series, []);
        $unavailable = 0;

        foreach ($readings as $reading) {
            if ($reading['reference'] === null) {
                continue;
            }

            foreach ($series as $name) {
                if ($reading[$name] === null) {
                    $unavailable++;

                    continue 2;
                }
            }

            foreach ($series as $name) {
                $differences[$name][] = $reading[$name] - $reading['reference'];
            }
        }

        return ['count' => count($differences[$series[0]]), 'unavailable' => $unavailable, 'deviations' => array_map(self::deviation(...), $differences)];
    }

    /**
     * @param  list<float>  $values
     * @return Deviation
     */
    private static function deviation(array $values): array
    {
        return $values === []
            ? ['mae' => null, 'bias' => null, 'min' => null, 'max' => null]
            : ['mae' => array_sum(array_map(abs(...), $values)) / count($values), 'bias' => array_sum($values) / count($values), 'min' => min($values), 'max' => max($values)];
    }
}
