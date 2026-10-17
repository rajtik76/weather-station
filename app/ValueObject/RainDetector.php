<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Rain in a noise window: loud at 8 kHz, ringing the shield around 1 kHz, and lasting the whole window.
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

    /**
     * Levels in hundredths of a dB, as stored; differences are taken before the division, so a threshold is exact.
     *
     * @param  list<int>  $bands  the 26 band levels, 25 Hz to 8 kHz
     */
    public static function hears(array $bands, int $laeq, int $la90): bool
    {
        $high = $bands[self::HIGH_BAND] ?? null;
        $ring = $bands[self::RING_BAND] ?? null;
        $below = $bands[self::RING_BAND - 1] ?? null;
        $above = $bands[self::RING_BAND + 1] ?? null;

        if ($high === null || $ring === null || $below === null || $above === null) {
            return false;
        }

        return $high / 100 >= self::MIN_HIGH_DB
            && ($ring - max($below, $above)) / 100 >= self::MIN_RING_DB
            && ($laeq - $la90) / 100 <= self::MAX_LAEQ_ABOVE_LA90_DB;
    }
}
