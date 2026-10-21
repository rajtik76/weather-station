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

function heardAsRain(float $high, float $ring, float $laeq, float $la90, float $humidity = 60.0): bool
{
    $bands = ringingSpectrum((int) round($ring * 100), (int) round($high * 100));

    return RainDetector::hears($bands, (int) round($laeq * 100), (int) round($la90 * 100), (int) round($humidity * 100));
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
    expect(RainDetector::hears(array_slice(ringingSpectrum(300, 5000), 0, 25), 6600, 5600, 9000))->toBeFalse();
});

it('reads the levels as the protocol stores them, in hundredths of dB', function (int $high, int $laeq, int $la90, bool $isRain): void {
    expect(RainDetector::hears(ringingSpectrum(300, $high), $laeq, $la90, 6000))->toBe($isRain);
})->with([
    'rain' => [5000, 6600, 5600, true],
    'too quiet at the top' => [4400, 6600, 5600, false],
    'a transient' => [5000, 6600, 4500, false],
    'twenty dB apart, odd hundredths' => [5000, 6543, 4543, true],
]);

it('hears light rain as a steady hiss at the top in humid air, without the ring', function (float $high, float $ring, float $laeq, float $la90, float $humidity): void {
    expect(heardAsRain($high, $ring, $laeq, $la90, $humidity))->toBeTrue();
})->with([
    '21 October 2026 18:39' => [43.8, 0.5, 60.8, 54.6, 84.1],
    '10 October 2026 12:29' => [48.4, -0.8, 62.0, 51.3, 90.3],
    'on every threshold' => [40.0, 0.0, 61.0, 50.0, 83.0],
]);

it('hears no light rain without all three signs', function (float $high, float $laeq, float $la90, float $humidity): void {
    expect(heardAsRain($high, 0.0, $laeq, $la90, $humidity))->toBeFalse();
})->with([
    // 18 October 2026 15:59, sunny.
    'gusty wind' => [50.6, 65.0, 51.1, 76.9],
    'humid but quiet at the top' => [39.99, 58.0, 50.0, 91.0],
    'just under the humidity threshold' => [45.0, 58.0, 50.0, 82.99],
    'just over the steadiness threshold' => [45.0, 61.01, 50.0, 90.0],
]);
