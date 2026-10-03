<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\ForecastAccuracy;

/**
 * The latest experiment version scored on its own forecasts and appended to the score; shown and base stay as scored.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 * @phpstan-import-type Scored from ForecastAccuracy
 */
final readonly class ExperimentalForecastScore
{
    /**
     * @param  Score  $score  ForecastScore::of() over the same $scored
     * @param  non-empty-list<Scored>  $scored
     * @param  array<string, array{model?: string, correction?: int}>  $tookOver
     * @return Score
     */
    public static function onto(array $score, array $scored, array $tookOver, int $lastIssued): array
    {
        $latest = null;

        foreach ($scored as $one) {
            $latest = $one['experiment'] ?? $latest;
        }

        if ($latest === null) {
            return $score;
        }

        $prototype = [];

        foreach ($scored as $one) {
            if (isset($one['experiment']) && $one['experiment']['version'] === $latest['version']) {
                $prototype[] = [...$one, 'corrected' => $one['experiment']['miss'], 'base' => null];
            }
        }

        if ($prototype === []) {
            return $score;
        }

        $prototypeDays = array_column(ForecastScore::byDay($prototype, $tookOver, $lastIssued), null, 0);

        return [
            ...$score,
            'days' => array_map(fn (array $day): array => [
                ...$day,
                $prototypeDays[$day[0]][2] ?? null,
                $prototypeDays[$day[0]][3] ?? null,
                $prototypeDays[$day[0]][5] ?? null,
                $prototypeDays[$day[0]][6] ?? null,
                $prototypeDays[$day[0]][1] ?? 0,
            ], $score['days']),
            'byHour' => array_map(
                fn (array $shown, array $prototypeHour): array => [...$shown, ...$prototypeHour],
                $score['byHour'],
                ForecastScore::byHour($prototype),
            ),
            'experiment' => ['version' => $latest['version'], 'synthetic' => $latest['synthetic']],
        ];
    }
}
