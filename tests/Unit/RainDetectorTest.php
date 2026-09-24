<?php

declare(strict_types=1);

use App\ValueObject\RainDetector;

/**
 * A flat 30 dB spectrum with the 8 kHz band and the shield's 1 kHz band set,
 * and the 1 kHz neighbours at 40 dB.
 *
 * @return list<float|null>
 */
function rainSpectrum(?float $high, ?float $ring): array
{
    $bands = array_fill(0, 26, 30.0);
    $bands[15] = 40.0;
    $bands[17] = 40.0;
    $bands[16] = $ring === null ? null : 40.0 + $ring;
    $bands[25] = $high;

    return $bands;
}

it('hears rain loud at the top with the shield ringing', function (float $high, float $ring): void {
    expect(RainDetector::hears(rainSpectrum($high, $ring)))->toBeTrue();
})->with([
    // Windows of 24 September 2026, confirmed by the gauge or the owner.
    'heavy drops' => [60.8, 5.2],
    'the weakest confirmed window' => [52.6, 2.6],
    'on both thresholds' => [45.0, 2.0],
]);

it('hears no rain without both signs', function (?float $high, ?float $ring): void {
    expect(RainDetector::hears(rainSpectrum($high, $ring)))->toBeFalse();
})->with([
    'tyres on a wet road' => [50.8, 0.6],
    'a quiet ring' => [36.0, 3.0],
    'just under the top threshold' => [44.9, 5.0],
    'just under the ring threshold' => [55.0, 1.9],
    'a band missing' => [55.0, null],
]);
