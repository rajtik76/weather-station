<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Forecast;

/**
 * A stored forecast as the sky lists it: temperature and rain only. The
 * service forecasts humidity and pressure too, and they stay in the stored
 * row, but nobody reads them ahead.
 *
 * @phpstan-type Hour array{hours: int, clock: string, t: float, tLow: float, tHigh: float, trend: string, rain: int, sky: array{icon: string, label: string, tone: string}}
 *
 * @phpstan-import-type Horizon from Forecast
 */
final readonly class ForecastHours
{
    /** A forecast hour this close to the one before it shows no trend. */
    private const float STEADY_CELSIUS = 0.3;

    /**
     * Each hour's trend against the one before it; the first against the newest reading.
     *
     * @param  float|null  $newest  the newest reading's temperature
     * @return list<Hour>
     */
    public static function of(Forecast $forecast, ?float $newest): array
    {
        $previous = $newest;
        $hours = [];

        foreach ($forecast->data as $horizon) {
            $hours[] = self::hour($forecast->issued_at, $horizon, $previous);
            $previous = $horizon['temperature']['mid'];
        }

        return $hours;
    }

    /**
     * @param  Horizon  $horizon
     * @param  float|null  $previous  the hour before's median, or the newest reading's temperature
     * @return Hour
     */
    private static function hour(int $issuedAt, array $horizon, ?float $previous): array
    {
        $temperature = $horizon['temperature'];
        $change = $previous === null ? 0.0 : $temperature['mid'] - $previous;
        $at = $issuedAt + $horizon['hours'] * 3600;
        $rain = (int) round($horizon['rain_probability'] * 100);

        return [
            'hours' => $horizon['hours'],
            'clock' => LocalTime::of($at)->clock(),
            't' => round($temperature['mid'], 1),
            'tLow' => round($temperature['low'], 1),
            'tHigh' => round($temperature['high'], 1),
            // Under STEADY_CELSIUS either way reads as holding: the median wobbles by tenths.
            'trend' => match (true) {
                $change >= self::STEADY_CELSIUS => 'rising',
                $change <= -self::STEADY_CELSIUS => 'falling',
                default => 'steady',
            },
            'rain' => $rain,
            'sky' => Sky::forHour($at, $rain),
        ];
    }
}
