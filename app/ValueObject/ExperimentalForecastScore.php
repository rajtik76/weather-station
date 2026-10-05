<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ScorePeriod;
use App\Queries\ForecastAccuracy;

/**
 * The latest experiment version scored on its own forecasts and appended to the score; shown and base stay as scored.
 * By hour it is added only to a period whose forecasts it covers from the first one; a period with none is covered.
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
    public static function onto(array $score, array $scored, array $tookOver, int $lastIssued, int $now): array
    {
        $newest = null;

        foreach ($scored as $one) {
            $newest = $one['experiment'] ?? $newest;
        }

        if ($newest === null) {
            return $score;
        }

        $prototype = [];

        foreach ($scored as $one) {
            if (isset($one['experiment']) && $one['experiment']['version'] === $newest['version']) {
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

        $since = min(array_column($prototype, 'issuedAt'));
        $byHour = $score['byHour'];
        $covers = [];

        foreach (ScorePeriod::cases() as $period) {
            $shown = ForecastScore::within($scored, $period, $now);

            if ($shown === []) {
                $covers[] = $period->value;

                continue;
            }

            if ($since > min(array_column($shown, 'issuedAt'))) {
                continue;
            }

            $covers[] = $period->value;
            $byHour[$period->value] = array_map(
                fn (HourOfDayScore $hour, HourOfDayScore $prototypeHour): HourOfDayScore => $hour->withExperiment($prototypeHour),
                $byHour[$period->value],
                ForecastScore::byHour(ForecastScore::within($prototype, $period, $now)),
            );
        }

        return [
            ...$score,
            'days' => array_map(fn (DayScore $day): DayScore => $day->withExperiment($prototypeDays[$day->date] ?? null), $score['days']),
            'byHour' => $byHour,
            'experiment' => ['version' => $newest['version'], 'synthetic' => $newest['synthetic'], 'since' => $since, 'covers' => $covers],
        ];
    }
}
