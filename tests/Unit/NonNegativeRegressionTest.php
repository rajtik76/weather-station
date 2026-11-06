<?php

declare(strict_types=1);

use App\ValueObject\NonNegativeRegression;

it('learns a heating response from known paired observations', function (): void {
    $coefficients = NonNegativeRegression::fit([[0.0], [1.0], [2.0], [3.0]], [0.0, 2.0, 4.0, 6.0]);

    expect($coefficients[0])->toEqualWithDelta(2.0, 0.01);
});

it('never learns to warm the sensor when the reference is warmer', function (): void {
    expect(NonNegativeRegression::fit([[1.0], [2.0]], [-1.0, -2.0]))->toBe([0.0]);
});

it('learns a negative coefficient on an unconstrained column', function (): void {
    $coefficients = NonNegativeRegression::fit([[1.0, 1.0], [1.0, -1.0], [2.0, 1.0], [2.0, -1.0]], [1.0, 3.0, 3.0, 5.0], unconstrained: [1]);

    expect($coefficients[0])->toEqualWithDelta(2.0, 0.05)
        ->and($coefficients[1])->toBeLessThan(-0.9);
});

it('handles a feature that stayed zero throughout calibration', function (): void {
    expect(NonNegativeRegression::fit([[0.0, 1.0], [0.0, 2.0]], [2.0, 4.0])[0])->toBe(0.0);
});

it('rejects an empty calibration set', function (): void {
    expect(fn (): array => NonNegativeRegression::fit([], []))->toThrow(InvalidArgumentException::class);
});

it('rejects different feature and target counts', function (): void {
    expect(fn (): array => NonNegativeRegression::fit([[1.0]], []))->toThrow(InvalidArgumentException::class);
});

it('rejects inconsistent feature counts', function (): void {
    expect(fn (): array => NonNegativeRegression::fit([[1.0], [1.0, 2.0]], [1.0, 2.0]))->toThrow(InvalidArgumentException::class);
});

it('rejects negative heating features', function (): void {
    expect(fn (): array => NonNegativeRegression::fit([[-1.0]], [1.0]))->toThrow(InvalidArgumentException::class);
});

it('rejects non-finite targets', function (): void {
    expect(fn (): array => NonNegativeRegression::fit([[1.0]], [INF]))->toThrow(InvalidArgumentException::class);
});
