<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Rain in a noise window, by either of two rules. Heavy rain: loud at 8 kHz, ringing the shield around 1 kHz,
 * lasting the whole window. Light rain: no ring, but a steady hiss at 8 kHz in humid air.
 * Wet-road tyres are loud at 8 kHz without the ring; a single transient rings the shield but lifts LAeq far above LA90.
 * Thresholds fit this mounting.
 */
final readonly class RainDetector
{
    /** 8 kHz, the top band. */
    private const int HIGH_BAND = 25;

    /** 1 kHz, where the shield rings. */
    private const int RING_BAND = 16;

    /** Rain read 47-61 dB here; dry windows stayed under 40. */
    private const float MIN_HIGH_DB = 45.0;

    /** Rain rose 2.2-5.2 dB above the louder neighbour; dry windows at most 1.1, a wet road 0.6. */
    private const float MIN_RING_DB = 2.0;

    /** Rain held LAeq 8.8-16.1 dB above LA90; a transient reached 26.0. */
    private const float MAX_LAEQ_ABOVE_LA90_DB = 20.0;

    /** Light rain read 40.3-48.4 dB here without a ring. */
    private const float LIGHT_MIN_HIGH_DB = 40.0;

    /** Light rain held 6.2-10.8 dB; gusty wind 11.7-13.9. */
    private const float LIGHT_MAX_LAEQ_ABOVE_LA90_DB = 11.0;

    /** Below this, dry windows reached the light-rain levels. */
    private const float LIGHT_MIN_HUMIDITY_PERCENT = 83.0;

    /**
     * Levels in hundredths of a dB and humidity in hundredths of a percent, as stored; differences are taken
     * before the division, so a threshold is exact.
     *
     * @param  list<int>  $bands  the 26 band levels, 25 Hz to 8 kHz
     */
    public static function hears(array $bands, int $laeq, int $la90, int $humidity): bool
    {
        return self::hearsHeavy($bands, $laeq, $la90) || self::hearsLight($bands, $laeq, $la90, $humidity);
    }

    /**
     * @param  list<int>  $bands  the 26 band levels, 25 Hz to 8 kHz
     */
    public static function hearsHeavy(array $bands, int $laeq, int $la90): bool
    {
        if (! self::carriesBands($bands)) {
            return false;
        }

        return $bands[self::HIGH_BAND] / 100 >= self::MIN_HIGH_DB
            && ($bands[self::RING_BAND] - max($bands[self::RING_BAND - 1], $bands[self::RING_BAND + 1])) / 100 >= self::MIN_RING_DB
            && ($laeq - $la90) / 100 <= self::MAX_LAEQ_ABOVE_LA90_DB;
    }

    /**
     * @param  list<int>  $bands  the 26 band levels, 25 Hz to 8 kHz
     */
    private static function hearsLight(array $bands, int $laeq, int $la90, int $humidity): bool
    {
        if (! self::carriesBands($bands)) {
            return false;
        }

        return $bands[self::HIGH_BAND] / 100 >= self::LIGHT_MIN_HIGH_DB
            && ($laeq - $la90) / 100 <= self::LIGHT_MAX_LAEQ_ABOVE_LA90_DB
            && $humidity / 100 >= self::LIGHT_MIN_HUMIDITY_PERCENT;
    }

    /**
     * @param  list<int>  $bands
     */
    private static function carriesBands(array $bands): bool
    {
        return isset($bands[self::HIGH_BAND], $bands[self::RING_BAND], $bands[self::RING_BAND - 1], $bands[self::RING_BAND + 1]);
    }
}
