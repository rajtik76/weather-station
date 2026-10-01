<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\ForecastAccuracy;

/**
 * The reference station's skill laid beside the balcony's: per horizon for
 * the verdict table, and per day of the balcony's own score for the skill
 * chart, matched by date, so the two lines share one axis. A day or horizon
 * the reference has no score for is null and draws as a gap.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 */
final readonly class ReferenceSkill
{
    /** A day row's skill column, as ForecastAccuracy lays it out. */
    private const int DAY_SKILL = 2;

    /**
     * @param  list<Score>  $reference
     * @return array<int, ?float> by hours ahead
     */
    public static function byHorizon(array $reference): array
    {
        return array_column(array_map(
            fn (array $score): array => ['hours' => $score['hours'], 'skill' => $score['shown']['skill']],
            $reference,
        ), 'skill', 'hours');
    }

    /**
     * @param  Score  $score  the balcony's, whose days set the axis
     * @param  list<Score>  $reference
     * @return list<?float>
     */
    public static function byDay(array $score, array $reference): array
    {
        $same = array_find($reference, fn (array $candidate): bool => $candidate['hours'] === $score['hours']);
        $skills = $same === null ? [] : array_column($same['days'], self::DAY_SKILL, 0);

        return array_map(fn (array $day): ?float => $skills[$day[0]] ?? null, $score['days']);
    }
}
