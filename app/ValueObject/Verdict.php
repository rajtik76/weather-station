<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Queries\ForecastAccuracy;

/**
 * The page's answer: the forecast as shown, six hours ahead, against the
 * naive guess, and every horizon's skill beside it so the six is not the
 * only number picked. Independent of the accuracy panel's horizon choice,
 * so the headline never changes under the reader's pointer.
 *
 * @phpstan-type Answer array{hours: int, ready: bool, count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float, horizons: list<array{hours: int, skill: ?float}>}
 *
 * @phpstan-import-type Score from ForecastAccuracy
 */
final readonly class Verdict
{
    /** The horizon the page's question asks about; the verdict falls back to the longest scored until it has come true. */
    public const int HOURS = 6;

    /** A day of forecasts, one per ten minutes: fewer and the verdict says it is too early. */
    private const int MIN_FORECASTS = 144;

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
