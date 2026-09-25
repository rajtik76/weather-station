<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a forecast accuracy reads at a glance: x-accuracy-percent colours the
 * figure by it.
 */
enum AccuracyGrade: string
{
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';

    private const float GOOD_FROM = 80.0;

    private const float FAIR_FROM = 50.0;

    /** Grade the percentage as printed, so a 79.6 shown as 80 % is not coloured as below it. */
    public static function of(float $percent): self
    {
        $shown = round($percent);

        return match (true) {
            $shown >= self::GOOD_FROM => self::Good,
            $shown >= self::FAIR_FROM => self::Fair,
            default => self::Poor,
        };
    }
}
