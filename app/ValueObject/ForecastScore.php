<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;
use App\Queries\ForecastAccuracy;

/**
 * One horizon's score from the forecasts ForecastAccuracy paired with what came. Pure arithmetic on the pairs; shapes are documented in ForecastAccuracy.
 *
 * @phpstan-import-type Score from ForecastAccuracy
 * @phpstan-import-type Scored from ForecastAccuracy
 * @phpstan-import-type Miss from ForecastAccuracy
 * @phpstan-import-type Rain from ForecastAccuracy
 * @phpstan-import-type Band from Forecast
 */
final readonly class ForecastScore
{
    /**
     * @param  non-empty-list<Scored>  $scored  oldest first
     * @param  array<string, array{model?: string, correction?: int}>  $tookOver  what took over, by the day it did
     * @return Score
     */
    public static function of(int $hours, array $scored, array $tookOver, int $lastIssued): array
    {
        return [
            'hours' => $hours,
            'days' => self::byDay($scored, $tookOver, $lastIssued),
            ...self::compared($scored),
            // Every hour scored, not only those with a base: the headline is no comparison.
            'shown' => self::figures($scored, 'corrected'),
            'rain' => self::rain($scored),
            'byHour' => self::byHour($scored),
        ];
    }

    /**
     * @param  Band  $band
     * @return Miss
     */
    public static function miss(float $truth, array $band): array
    {
        return [
            'inRange' => $truth >= $band['low'] && $truth <= $band['high'],
            'difference' => $truth - $band['mid'],
            'width' => $band['high'] - $band['low'],
        ];
    }

    /**
     * Includes days with none scored, so a service gap shows as a gap and a model that took over today is marked.
     *
     * @param  non-empty-list<Scored>  $scored  oldest first
     * @param  array<string, array{model?: string, correction?: int}>  $tookOver
     * @return list<DayScore>
     */
    public static function byDay(array $scored, array $tookOver, int $lastIssued): array
    {
        $grouped = [];

        foreach ($scored as $one) {
            $grouped[$one['date']][] = $one;
        }

        $days = [];

        foreach (LocalTime::of($scored[0]['issuedAt'])->daysThrough(LocalTime::of($lastIssued)) as $day) {
            $date = $day->date();
            ['corrected' => $corrected, 'base' => $base] = isset($grouped[$date]) ? self::compared($grouped[$date]) : ['corrected' => null, 'base' => null];

            $days[] = new DayScore(
                date: $date,
                shown: $corrected,
                base: $base,
                modelTookOver: $tookOver[$date]['model'] ?? null,
                correctionTookOver: $tookOver[$date]['correction'] ?? null,
            );
        }

        return $days;
    }

    /**
     * @param  list<Scored>  $scored
     * @return list<HourOfDayScore> all 24 local hours
     */
    public static function byHour(array $scored): array
    {
        $grouped = [];

        foreach ($scored as $one) {
            $grouped[$one['hour']][] = $one['corrected'];
        }

        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            if (! isset($grouped[$hour])) {
                $hours[] = new HourOfDayScore;

                continue;
            }

            $count = count($grouped[$hour]);
            $differences = array_column($grouped[$hour], 'difference');
            $distances = array_map(abs(...), $differences);

            $hours[] = new HourOfDayScore(
                count: $count,
                inRange: self::percent(array_column($grouped[$hour], 'inRange')),
                error: round(array_sum($distances) / $count, 2),
                worst: round(max($distances), 2),
                bias: round(array_sum($differences) / $count, 2),
            );
        }

        return $hours;
    }

    /**
     * Shown and base on the same hours, so the gap is the correction's and not a different mix of days.
     *
     * @param  non-empty-list<Scored>  $scored
     * @return array{corrected: ScoreFigures, base: ?ScoreFigures}
     */
    private static function compared(array $scored): array
    {
        $withBase = array_values(array_filter($scored, fn (array $one): bool => $one['base'] !== null));

        if ($withBase === []) {
            return ['corrected' => self::figures($scored, 'corrected'), 'base' => null];
        }

        return ['corrected' => self::figures($withBase, 'corrected'), 'base' => self::figures($withBase, 'base')];
    }

    /**
     * Over the hours that have it; null when none does.
     *
     * @param  list<Scored>  $scored
     * @param  'corrected'|'base'  $which
     * @return ($which is 'corrected' ? ScoreFigures : ?ScoreFigures)
     */
    private static function figures(array $scored, string $which): ?ScoreFigures
    {
        $misses = [];
        $paired = [];

        foreach ($scored as $one) {
            if ($one[$which] === null) {
                continue;
            }

            $misses[] = $one[$which];

            if ($one['naive'] !== null) {
                $paired[] = [abs($one[$which]['difference']), $one['naive']];
            }
        }

        if ($misses === []) {
            return null;
        }

        $missed = array_sum(array_column($paired, 0));
        $guessMissed = array_sum(array_column($paired, 1));

        return new ScoreFigures(
            count: count($misses),
            // A guess that never missed leaves nothing to beat.
            skill: $guessMissed > 0 ? round(100 * (1 - $missed / $guessMissed)) : null,
            error: $paired === [] ? null : round($missed / count($paired), 2),
            naive: $paired === [] ? null : round($guessMissed / count($paired), 2),
            inRange: self::percent(array_column($misses, 'inRange')),
            width: round(array_sum(array_column($misses, 'width')) / count($misses), 2),
        );
    }

    /**
     * @param  list<Scored>  $scored
     * @return Rain
     */
    private static function rain(array $scored): array
    {
        $rain = array_column(array_filter($scored, fn (array $one): bool => $one['rained'] === true), 'chance');
        $dry = array_column(array_filter($scored, fn (array $one): bool => $one['rained'] === false), 'chance');

        return [
            'count' => count($rain) + count($dry),
            'cases' => count($rain),
            'chanceWhenRain' => self::meanPercent($rain),
            'chanceWhenDry' => self::meanPercent($dry),
        ];
    }

    /**
     * @param  non-empty-list<bool>  $rights
     */
    private static function percent(array $rights): float
    {
        return round(100 * count(array_filter($rights)) / count($rights));
    }

    /**
     * @param  list<float>  $chances  0-1
     */
    private static function meanPercent(array $chances): ?float
    {
        return $chances === [] ? null : round(100 * array_sum($chances) / count($chances));
    }
}
