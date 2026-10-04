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

        $prototypeDays = [];

        foreach (ForecastScore::byDay($prototype, $tookOver, $lastIssued) as $day) {
            $prototypeDays[$day->date] = $day->shown;
        }

        return [
            ...$score,
            'days' => array_map(fn (DayScore $day): DayScore => $day->withExperiment($prototypeDays[$day->date] ?? null), $score['days']),
            'byHour' => array_map(
                fn (HourOfDayScore $shown, HourOfDayScore $prototypeHour): HourOfDayScore => $shown->withExperiment($prototypeHour),
                $score['byHour'],
                ForecastScore::byHour($prototype),
            ),
            'experiment' => ['version' => $latest['version'], 'synthetic' => $latest['synthetic']],
        ];
    }
}
