<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Hears rain in a noise window's third-octave spectrum. What the microphone
 * picks up is mostly drops from the roof on the plastic radiation shield:
 * loud at the top of the spectrum, with the shield ringing around 1 kHz.
 * Tyres on a wet road are just as loud at 8 kHz but do not ring the shield,
 * so both conditions are needed. Drizzle too fine to drip is not heard.
 *
 * Calibrated on the rain of 24 September 2026 against the ČHMÚ gauge at
 * Plzeň-Mikulka and the owner's own log; thresholds belong to this mounting.
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

    /**
     * @param  list<float|int|null>  $bands  the 26 band levels in dB, 25 Hz to 8 kHz
     */
    public static function hears(array $bands): bool
    {
        $high = $bands[self::HIGH_BAND] ?? null;
        $ring = $bands[self::RING_BAND] ?? null;
        $below = $bands[self::RING_BAND - 1] ?? null;
        $above = $bands[self::RING_BAND + 1] ?? null;

        if ($high === null || $ring === null || $below === null || $above === null) {
            return false;
        }

        return $high >= self::MIN_HIGH_DB && $ring - max($below, $above) >= self::MIN_RING_DB;
    }
}
