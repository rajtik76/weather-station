<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;

/**
 * The models racing for the shown temperature and their bands on a stored horizon: `base` from the horizon's base,
 * the rest from its `candidates`.
 *
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Band from Forecast
 */
final readonly class RaceEntrants
{
    public const string BASE = 'base';

    public const string CORRECTION = 'correction';

    /** Simplest first, the tie order: at night light-v5 and light-v6 answer the base band. */
    public const array NAMES = [self::BASE, self::CORRECTION, 'light-v5', 'light-v6'];

    /** Leads a part of the day nobody has points in yet: the forecast shown before the race. */
    public const string FALLBACK = self::CORRECTION;

    /**
     * In NAMES order: jsonb stores the candidates in an order of its own.
     *
     * @param  Horizon  $horizon
     * @return array<string, Band>
     */
    public static function bands(array $horizon): array
    {
        $bands = [];

        foreach (self::NAMES as $name) {
            $band = $name === self::BASE ? $horizon['base']['temperature'] ?? null : $horizon['candidates'][$name] ?? null;

            if ($band !== null) {
                $bands[$name] = $band;
            }
        }

        return $bands;
    }

    /**
     * @param  Horizon  $horizon
     */
    public static function areAllIn(array $horizon): bool
    {
        return count(self::bands($horizon)) === count(self::NAMES);
    }

    /**
     * The correction's band joins the candidates while the shown band is still the service's corrected one.
     *
     * @param  list<Horizon>  $horizons
     * @return list<Horizon>
     */
    public static function withCorrection(array $horizons): array
    {
        return array_map(
            fn (array $horizon): array => isset($horizon['shown_by']) || isset($horizon['candidates'][self::CORRECTION])
                ? $horizon
                : [...$horizon, 'candidates' => [...$horizon['candidates'] ?? [], self::CORRECTION => $horizon['temperature']]],
            $horizons,
        );
    }
}
