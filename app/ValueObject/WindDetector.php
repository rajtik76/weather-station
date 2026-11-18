<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\WindIntensity;

/**
 * Wind in a noise window: the MEMS microphone in the shield rumbles at 25 Hz, A-weighting all but hides it from LAeq.
 * Wind falls steeply from 25 Hz; morning traffic and engines idling stay flat to 31.5 Hz and drop further by 160 Hz.
 * Fitted on 23.9.-18.11.2026 against the owner's notes (18.10. and 18.11.) and Mikulka gusts. Thresholds fit this mounting.
 */
final readonly class WindDetector
{
    /** 25 Hz, the lowest band. */
    private const int RUMBLE_BAND = 0;

    /** 31.5 Hz. */
    private const int NEXT_BAND = 1;

    /** 160 Hz. */
    private const int TRAFFIC_BAND = 8;

    /** Noted gusts read 73-83 dB here; calm windows stayed under it all but 0.3 % of the time. */
    private const float MIN_RUMBLE_DB = 70.0;

    /** Wind fell 2.9-5.7 dB to 31.5 Hz; morning traffic 0.7-2.6. */
    private const float MIN_FIRST_STEP_DB = 2.5;

    /** Wind fell 18.5-22.5 dB to 160 Hz; morning traffic 24-27. */
    private const float MAX_FALL_TO_TRAFFIC_DB = 23.0;

    /** Nine in ten noted gust windows, three in four with Mikulka gusting to 14 m/s or more. */
    private const float STRONG_RUMBLE_DB = 75.0;

    /**
     * Levels in hundredths of a dB, as stored.
     *
     * @param  list<int>  $bands  the 26 band levels, 25 Hz to 8 kHz
     */
    public static function hears(array $bands): ?WindIntensity
    {
        if (! isset($bands[self::RUMBLE_BAND], $bands[self::NEXT_BAND], $bands[self::TRAFFIC_BAND])) {
            return null;
        }

        $rumble = $bands[self::RUMBLE_BAND];

        $windy = $rumble / 100 >= self::MIN_RUMBLE_DB
            && ($rumble - $bands[self::NEXT_BAND]) / 100 >= self::MIN_FIRST_STEP_DB
            && ($rumble - $bands[self::TRAFFIC_BAND]) / 100 <= self::MAX_FALL_TO_TRAFFIC_DB;

        if (! $windy) {
            return null;
        }

        return $rumble / 100 >= self::STRONG_RUMBLE_DB ? WindIntensity::Strong : WindIntensity::Breeze;
    }
}
