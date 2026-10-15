<?php

declare(strict_types=1);

use App\ValueObject\ShieldModel;
use App\ValueObject\ShieldSample;
use App\ValueObject\SolarPosition;

it('subtracts estimated heating and preserves the measured temperature', function (): void {
    $sample = new ShieldSample(1000, 22.5, 2000.0, new SolarPosition(20.0, 110.0), true, [1.0, 0.5]);
    $model = new ShieldModel([2.0, 3.0], 2500.0, 10.0);

    expect($model->temperature($sample))->toBe(19.0)
        ->and($model->heating($sample))->toBe(3.5)
        ->and($sample->measured)->toBe(22.5);
});

it('returns no estimate outside its calibrated light range', function (): void {
    $sample = new ShieldSample(1000, 22.5, 4000.0, new SolarPosition(20.0, 110.0), true, [1.0]);

    expect(new ShieldModel([2.0], 2500.0, 10.0)->temperature($sample))->toBeNull();
});

it('returns no estimate for heating beyond the calibrated range', function (): void {
    $sample = new ShieldSample(1000, 22.5, 1000.0, new SolarPosition(20.0, 110.0), true, [20.0]);

    expect(new ShieldModel([2.0], 2500.0, 10.0)->temperature($sample))->toBeNull();
});

it('returns no estimate for an incompatible feature vector', function (): void {
    $sample = new ShieldSample(1000, 22.5, 1000.0, new SolarPosition(20.0, 110.0), true, [1.0, 2.0]);

    expect(new ShieldModel([2.0], 2500.0, 10.0)->temperature($sample))->toBeNull();
});
