<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\LocalTime;

enum ScorePeriod: string
{
    case Yesterday = 'yesterday';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Yesterday => 'Yesterday',
            self::Week => '7 days',
            self::Month => '30 days',
        };
    }

    /**
     * Targets from the first bound up to, not including, the second; Month holds everything scored, the scores already cover the page's last 30 days.
     *
     * @return array{int, int}
     */
    public function bounds(int $now): array
    {
        $today = LocalTime::of($now)->midnight()->timestamp;

        return match ($this) {
            self::Yesterday => [LocalTime::of($today - 1)->midnight()->timestamp, $today],
            self::Week => [$now - 7 * 86400 + 1, PHP_INT_MAX],
            self::Month => [PHP_INT_MIN, PHP_INT_MAX],
        };
    }
}
