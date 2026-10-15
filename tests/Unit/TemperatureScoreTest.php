<?php

declare(strict_types=1);

use App\ValueObject\ShieldSample;
use App\ValueObject\SolarPosition;
use App\ValueObject\TemperatureScore;

it('compares raw and corrected errors on identical reference pairs and reports unavailable estimates', function (): void {
    $sun = new SolarPosition(20.0, 110.0);
    $pairs = [
        ['sample' => new ShieldSample(1000, 12.0, 1000.0, $sun, true, [1.0]), 'reference' => 10.0, 'heating' => 1.0],
        ['sample' => new ShieldSample(1600, 9.0, 1000.0, $sun, true, [1.0]), 'reference' => 10.0, 'heating' => 1.0],
        ['sample' => new ShieldSample(2200, 30.0, 10000.0, $sun, true, [1.0]), 'reference' => 10.0, 'heating' => null],
    ];

    expect(TemperatureScore::of($pairs))->toBe([
        'count' => 2, 'unavailable' => 1,
        'raw_mae' => 1.5, 'corrected_mae' => 1.5,
        'raw_bias' => 0.5, 'corrected_bias' => -0.5,
    ]);
});

it('does not invent scores for missing observations', function (): void {
    expect(TemperatureScore::of([]))->toBe([
        'count' => 0, 'unavailable' => 0,
        'raw_mae' => null, 'corrected_mae' => null,
        'raw_bias' => null, 'corrected_bias' => null,
    ]);
});
