<?php

declare(strict_types=1);

namespace App\Enums;

enum ChartRange: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** Smallest preset covering a span; anything wider falls to Month. */
    public static function forSpan(int $seconds): self
    {
        foreach (self::cases() as $range) {
            if ($seconds <= $range->durationSeconds()) {
                return $range;
            }
        }

        return self::Month;
    }

    public function durationSeconds(): int
    {
        return match ($this) {
            self::Hour => 3600,
            self::Day => 86400,
            self::Week => 604800,
            self::Month => 2592000,
        };
    }

    /** Bucket width in seconds; never finer than the ten-minute cadence, never wider than an hour. */
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
}
