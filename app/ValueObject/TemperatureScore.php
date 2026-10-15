<?php

declare(strict_types=1);

namespace App\ValueObject;

/** @phpstan-type Score array{count: int, unavailable: int, raw_mae: ?float, corrected_mae: ?float, raw_bias: ?float, corrected_bias: ?float} */
final readonly class TemperatureScore
{
    /**
     * @param  list<array{sample: ShieldSample, reference: float}>  $pairs
     * @return Score
     */
    public static function of(array $pairs, ShieldModel $model): array
    {
        $raw = $corrected = [];

        foreach ($pairs as $pair) {
            $temperature = $model->temperature($pair['sample']);

            if ($temperature === null) {
                continue;
            }

            $raw[] = $pair['sample']->measured - $pair['reference'];
            $corrected[] = $temperature - $pair['reference'];
        }

        $count = count($raw);

        return [
            'count' => $count,
            'unavailable' => count($pairs) - $count,
            'raw_mae' => $count === 0 ? null : array_sum(array_map(abs(...), $raw)) / $count,
            'corrected_mae' => $count === 0 ? null : array_sum(array_map(abs(...), $corrected)) / $count,
            'raw_bias' => $count === 0 ? null : array_sum($raw) / $count,
            'corrected_bias' => $count === 0 ? null : array_sum($corrected) / $count,
        ];
    }
}
