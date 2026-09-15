<?php

declare(strict_types=1);

namespace App\Enums;

enum ChartRange: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * The smallest preset that still covers a span.
     *
     * Zooming produces arbitrary windows, and the bucket width and label
     * precision should follow the span actually on screen rather than the
     * button that was last pressed. Everything below therefore keys off this.
     *
     * A month is the widest window the dashboard draws (Dashboard::MAX_SPAN_SECONDS),
     * so nothing wider ever arrives here; it falls to the month regardless.
     */
    public static function forSpan(int $seconds): self
    {
        foreach (self::cases() as $range) {
            if ($seconds <= $range->durationSeconds()) {
                return $range;
            }
        }

        return self::Month;
    }

    /** Nominal length, used to place a span and to seed the window. */
    public function durationSeconds(): int
    {
        return match ($this) {
            self::Hour => 3600,
            self::Day => 86400,
            self::Week => 604800,
            self::Month => 2592000,
        };
    }

    /**
     * Width of the buckets the window is averaged into, in seconds.
     *
     * Never finer than the station's own ten-minute cadence: the chart plots
     * a bucket per slot whether or not a reading landed in it, so an hour is
     * six points and a month is 720 rather than the 4,300 readings it holds.
     * Zooming in re-queries and the buckets narrow on their own. An hour is
     * as wide as they get - the strip is a thousand pixels across and a
     * month of hourly means still shows each day's rise and fall, which is
     * why the window stops at a month rather than widening the buckets.
     */
    public function bucketSeconds(): int
    {
        return match ($this) {
            self::Hour, self::Day => 600,
            self::Week => 1800,
            self::Month => 3600,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Hour',
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
        };
    }

    /** How precisely to name the window being shown. A year needs no clock. */
    public function stampFormat(): string
    {
        return match ($this) {
            self::Hour, self::Day => 'j. n. Y H:i',
            self::Week, self::Month => 'j. n. Y',
        };
    }
}
