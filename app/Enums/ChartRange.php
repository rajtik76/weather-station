<?php

declare(strict_types=1);

namespace App\Enums;

enum ChartRange: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * The smallest preset that still covers a span.
     *
     * Zooming produces arbitrary windows, and thinning and label precision
     * should follow the span actually on screen rather than the button that
     * was last pressed. Everything below therefore keys off this.
     */
    public static function forSpan(int $seconds): self
    {
        foreach (self::cases() as $range) {
            if ($seconds <= $range->durationSeconds()) {
                return $range;
            }
        }

        return self::Year;
    }

    /** Nominal length, used to place a span and to seed the window. */
    public function durationSeconds(): int
    {
        return match ($this) {
            self::Hour => 3600,
            self::Day => 86400,
            self::Week => 604800,
            self::Month => 2592000,
            self::Year => 31536000,
        };
    }

    /**
     * Keep one reading per this many seconds; zero keeps every reading.
     *
     * A year holds roughly 52,000 readings at the station's ten-minute
     * cadence - more than a chart should carry over the wire. Longer spans
     * keep one real reading per bucket instead of averaging, so every plotted
     * point stays an actual record. Zooming in re-queries and the thinning
     * relaxes on its own.
     */
    public function thinToSeconds(): int
    {
        return match ($this) {
            self::Hour, self::Day, self::Week => 0,
            self::Month => 3600,
            self::Year => 21600,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Hour',
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Year => 'Year',
        };
    }

    /** How precisely to name the window being shown. A year needs no clock. */
    public function stampFormat(): string
    {
        return match ($this) {
            self::Hour, self::Day => 'j. n. Y H:i',
            self::Week, self::Month, self::Year => 'j. n. Y',
        };
    }
}
