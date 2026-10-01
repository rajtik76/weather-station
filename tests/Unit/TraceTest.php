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

it('labels whole degrees at the place a spanned series puts them', function (): void {
    $ticks = Trace::ticks(10.0, 20.0, fn (float $value): float => Trace::levelOf($value, 10.0, 20.0));

    expect($ticks)->toHaveCount(3)
        ->and($ticks[0])->toBe(['value' => 10.0, 'y' => 92.0])
        ->and(end($ticks))->toBe(['value' => 20.0, 'y' => 8.0]);
});

it('falls to a finer step when no whole degree lies in the range', function (): void {
    $ticks = Trace::ticks(16.4, 16.7, fn (float $value): float => Trace::levelOf($value, 16.4, 16.7), [0.1, 0.2, 0.5, 1.0]);

    expect(array_column($ticks, 'value'))->toBe([16.4, 16.5, 16.6, 16.7]);
});

it('labels the ends of a range too narrow to hold a round value', function (): void {
    $ticks = Trace::ticks(16.41, 16.43, fn (float $value): float => 50.0, [0.5, 1.0]);

    expect(array_column($ticks, 'value'))->toBe([16.41, 16.43]);
});
