<?php

declare(strict_types=1);

use App\ValueObject\RainDetector;

/**
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

it('reads the bands as the protocol stores them, in hundredths of dB', function (): void {
    $stored = array_fill(0, 26, 3000);
    $stored[15] = 4000;
    $stored[17] = 4000;
    $stored[16] = 4300;

    expect(RainDetector::hearsStored([...array_slice($stored, 0, 25), 5000]))->toBeTrue()
        ->and(RainDetector::hearsStored([...array_slice($stored, 0, 25), 4400]))->toBeFalse();
});
