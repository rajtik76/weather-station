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

it('bands between an upper and a lower line', function (): void {
    $high = Trace::through([['x' => 25.0, 'y' => 50.0], ['x' => 75.0, 'y' => 8.0]]);
    $low = Trace::through([['x' => 25.0, 'y' => 50.0], ['x' => 75.0, 'y' => 92.0]]);

    expect($high->band($low))->toBe('M25,50L75,8L75,92L25,50Z');
});

it('draws points laid out by the caller as they are', function (): void {
    expect(Trace::through([['x' => 0.0, 'y' => 10.5], ['x' => 54.17, 'y' => 30.0]])->line())->toBe('M0,10.5L54.17,30');
});
