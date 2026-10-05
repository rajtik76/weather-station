<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\LocalTime;

enum ScorePeriod: string
{
    case Yesterday = 'yesterday';
    case Week = 'week';
    case Month = 'month';

    /**
     * Month holds everything scored: the scores already cover the page's last 30 days.
     *
     * @param  int  $target  the moment a forecast was for
     * @param  int  $latest  the newest measured slot
     */
    public function holds(int $target, int $latest): bool
    {
        $today = LocalTime::of($latest)->midnight()->timestamp;

        return match ($this) {
            self::Yesterday => $target >= LocalTime::of($today - 1)->midnight()->timestamp && $target < $today,
            self::Week => $target > $latest - 7 * 86400,
            self::Month => true,
        };
    }
}
