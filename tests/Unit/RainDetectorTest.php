<?php

declare(strict_types=1);

use App\ValueObject\RainDetector;

/**
 * @return list<int>
 */
function ringingSpectrum(int $ring, int $high): array
{
    $bands = array_fill(0, 26, 3000);
    $bands[15] = 4000;
    $bands[16] = 4000 + $ring;
    $bands[17] = 4000;
    $bands[25] = $high;

    return $bands;
}

function heardAsRain(float $high, float $ring, float $laeq, float $la90): bool
{
    $bands = ringingSpectrum((int) round($ring * 100), (int) round($high * 100));

    return RainDetector::hears($bands, (int) round($laeq * 100), (int) round($la90 * 100));
}

it('hears rain loud at the top with the shield ringing all window long', function (float $high, float $ring, float $laeq, float $la90): void {
    expect(heardAsRain($high, $ring, $laeq, $la90))->toBeTrue();
})->with([
    // Windows of 24 September 2026, confirmed by the gauge or the owner.
    'heavy drops' => [60.8, 5.2, 73.6, 57.4],
    'the weakest confirmed window' => [52.6, 2.6, 66.6, 56.0],
    'on every threshold' => [45.0, 2.0, 70.0, 50.0],
]);

it('hears no rain without all three signs', function (float $high, float $ring, float $laeq, float $la90): void {
    expect(heardAsRain($high, $ring, $laeq, $la90))->toBeFalse();
})->with([
    'tyres on a wet road' => [50.8, 0.6, 65.1, 57.4],
    'a quiet ring' => [36.0, 3.0, 66.0, 56.0],
    'just under the top threshold' => [44.9, 5.0, 66.0, 56.0],
    'just under the ring threshold' => [55.0, 1.9, 66.0, 56.0],
    // 17 October 2026 09:19, dry.
    'a transient ringing the shield' => [52.9, 4.3, 74.0, 48.0],
    'just over the transient threshold' => [55.0, 5.0, 70.01, 50.0],
]);

it('hears no rain in a spectrum missing its top band', function (): void {
    expect(RainDetector::hears(array_slice(ringingSpectrum(300, 5000), 0, 25), 6600, 5600))->toBeFalse();
});

it('reads the levels as the protocol stores them, in hundredths of dB', function (int $high, int $laeq, int $la90, bool $isRain): void {
    expect(RainDetector::hears(ringingSpectrum(300, $high), $laeq, $la90))->toBe($isRain);
})->with([
    'rain' => [5000, 6600, 5600, true],
    'too quiet at the top' => [4400, 6600, 5600, false],
    'a transient' => [5000, 6600, 4500, false],
    'twenty dB apart, odd hundredths' => [5000, 6543, 4543, true],
]);
