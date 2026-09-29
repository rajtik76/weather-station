<?php

declare(strict_types=1);

use App\ValueObject\Trace;

it('spans the box and keeps the extremes off its edges', function (): void {
    // Lowest at the bottom margin, highest at the top one, y growing down.
    expect(Trace::spanning([10.0, 20.0, 15.0])->line())->toBe('M0,92L50,8L100,50');
});

it('scales to given bounds instead of its own', function (): void {
    expect(Trace::spanning([10.0, 20.0], 0.0, 20.0)->line())->toBe('M0,50L100,8');
});

it('draws a single value and a flat series as a level line', function (): void {
    expect(Trace::spanning([21.5])->line())->toBe('M0,50L100,50')
        ->and(Trace::spanning([3.0, 3.0, 3.0])->line())->toBe('M0,50L50,50L100,50');
});

it('closes the area along the bottom', function (): void {
    expect(Trace::spanning([10.0, 20.0])->area())->toBe('M0,92L100,8L100,100L0,100Z');
});

it('centres each point in its own column', function (): void {
    // Four columns of 25: the points sit at their middles, where a grid of labels centres.
    expect(array_column(Trace::centred([1.0, 2.0, 3.0, 4.0], 1.0, 4.0)->points, 'x'))
        ->toBe([12.5, 37.5, 62.5, 87.5]);
});

it('bands between an upper and a lower line', function (): void {
    $high = Trace::centred([12.0, 14.0], 10.0, 14.0);
    $low = Trace::centred([12.0, 10.0], 10.0, 14.0);

    // Out along the top, back along the bottom.
    expect($high->band($low))->toBe('M25,50L75,8L75,92L25,50Z');
});
