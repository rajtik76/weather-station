<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\TemperatureHistory;

/**
 * @phpstan-import-type TemperatureRow from TemperatureHistory
 *
 * @phpstan-type Deviation array{mae: ?float, bias: ?float, min: ?float, max: ?float}
 * @phpstan-type Summary array{count: int, unavailable: int, measured: Deviation, estimated: Deviation}
 */
final readonly class TemperatureComparison
{
    /**
     * @param  list<TemperatureRow>  $readings
     * @return Summary
     */
    public static function summary(array $readings): array
    {
        $measured = $estimated = [];
        $unavailable = 0;

        foreach ($readings as $reading) {
            if ($reading['reference'] === null) {
                continue;
            }

            if ($reading['estimated'] === null) {
                $unavailable++;

                continue;
            }

            $measured[] = $reading['measured'] - $reading['reference'];
            $estimated[] = $reading['estimated'] - $reading['reference'];
        }

        return ['count' => count($measured), 'unavailable' => $unavailable, 'measured' => self::deviation($measured), 'estimated' => self::deviation($estimated)];
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
