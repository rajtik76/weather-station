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

it('labels the axis a fifth of the range inside the data, on the scale the trace is laid on', function (): void {
    $axis = Trace::axis(10.64, 14.21);

    // 0.714 in from either end, in to the tenths: 11.4 and 13.4.
    expect($axis)->toMatchArray(['low' => 10.64, 'high' => 14.21])
        ->and(array_column($axis['ticks'], 'value'))->toBe([13.4, 12.4, 11.4])
        ->and($axis['ticks'][2]['y'])->toBe(Trace::centred([11.4], 10.64, 14.21)->points[0]['y']);
});

it('narrows the axis by a tenth when its middle would fall between two', function (): void {
    // 12.9 to 17.9 labels 13.9 to 16.9; 12.9 to 17.8 reaches 16.8, whose middle would be 15.35.
    expect(array_column(Trace::axis(12.9, 17.9)['ticks'], 'value'))->toBe([16.9, 15.4, 13.9])
        ->and(array_column(Trace::axis(12.9, 17.8)['ticks'], 'value'))->toBe([16.7, 15.3, 13.9]);
});

it('labels a flat range once, in the middle', function (): void {
    expect(Trace::axis(12.0, 12.0)['ticks'])->toBe([['value' => 12.0, 'y' => 50.0]]);
});
