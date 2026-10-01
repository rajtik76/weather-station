<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;
use App\Queries\ForecastAccuracy;

/**
 * The page's answer: six hours ahead against the naive guess, plus every horizon's skill. Independent of the accuracy panel's horizon choice.
 *
 * @phpstan-type Answer array{hours: int, ready: bool, count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float, horizons: list<array{hours: int, skill: ?float}>}
 *
 * @phpstan-import-type Score from ForecastAccuracy
 */
final readonly class Verdict
{
    /** The verdict falls back to the longest scored horizon until this one has come true. */
    public const int HOURS = 6;

    /** A day of forecasts; fewer is "too early". */
    private const int MIN_FORECASTS = 24 * 3600 / Forecast::INTERVAL_SECONDS;

    /**
     * Null until a forecast has come true.
     *
     * @param  list<Score>  $scores
     * @return Answer|null
     */
    public static function of(array $scores): ?array
    {
        if ($scores === []) {
            return null;
        }

        $headline = array_find($scores, fn (array $score): bool => $score['hours'] === self::HOURS) ?? end($scores);
        $figures = $headline['shown'];

        return [
            'hours' => $headline['hours'],
            'ready' => $figures['skill'] !== null && $figures['count'] >= self::MIN_FORECASTS,
            ...$figures,
            'horizons' => array_map(fn (array $score): array => ['hours' => $score['hours'], 'skill' => $score['shown']['skill']], $scores),
        ];
    }
}
