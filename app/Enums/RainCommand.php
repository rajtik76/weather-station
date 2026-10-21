<?php

declare(strict_types=1);

namespace App\Enums;

/** Discord slash commands for owner reports. */
enum RainCommand: string
{
    case Rain = 'rain';
    case Dry = 'dry';

    public function raining(): bool
    {
        return $this === self::Rain;
    }

    public function description(): string
    {
        return match ($this) {
            self::Rain => 'Report that it is raining at the station',
            self::Dry => 'Report that it is not raining at the station',
        };
    }
}
