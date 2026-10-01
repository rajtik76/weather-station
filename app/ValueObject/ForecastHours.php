<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;

/**
 * A stored forecast as the pages list it: temperature and rain only.
 *
 * @phpstan-type Hour array{hours: int, at: int, clock: string, t: float, tLow: float, tHigh: float, rain: int}
 *
 * @phpstan-import-type Horizon from Forecast
 */
final readonly class ForecastHours
{
    /**
     * @return list<Hour>
     */
    public static function of(Forecast $forecast): array
    {
        return array_map(fn (array $horizon): array => self::hour($forecast->issued_at, $horizon), $forecast->data);
    }

    /**
     * @param  Horizon  $horizon
     * @return Hour
     */
    private static function hour(int $issuedAt, array $horizon): array
    {
        $temperature = $horizon['temperature'];
        $at = $issuedAt + $horizon['hours'] * 3600;

        return [
            'hours' => $horizon['hours'],
            // The epoch the hour is for; the forecast may start before the newest reading.
            'at' => $at,
            'clock' => LocalTime::of($at)->clock(),
            't' => round($temperature['mid'], 1),
            'tLow' => round($temperature['low'], 1),
            'tHigh' => round($temperature['high'], 1),
            'rain' => (int) round($horizon['rain_probability'] * 100),
        ];
    }
}
