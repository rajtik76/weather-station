<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\LocalTime;

/** Part of the local day a forecast's target falls in; the model race picks a winner per block. */
enum RaceBlock: string
{
    case Night = 'night';
    case Morning = 'morning';
    case Day = 'day';

    public static function at(int $timestamp): self
    {
        return self::ofHour(LocalTime::of($timestamp)->hour());
    }

    public static function ofHour(int $hour): self
    {
        return match (true) {
            $hour >= 6 && $hour < 12 => self::Morning,
            $hour >= 12 && $hour < 22 => self::Day,
            default => self::Night,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Night => 'Night',
            self::Morning => 'Morning',
            self::Day => 'Afternoon & evening',
        };
    }

    public function hours(): string
    {
        return match ($this) {
            self::Night => '22-6 h',
            self::Morning => '6-12 h',
            self::Day => '12-22 h',
        };
    }
}
