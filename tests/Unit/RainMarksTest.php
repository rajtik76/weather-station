<?php

declare(strict_types=1);

use App\ValueObject\RainMarks;

it('joins adjacent rainy windows into one stretch half a step either side', function (): void {
    expect(RainMarks::stretches([false, true, true, null, false]))->toBe([['from' => 12.5, 'to' => 62.5]]);
});

it('keeps separate showers apart and clips them to the trace', function (): void {
    expect(RainMarks::stretches([true, false, false, true]))->toBe([
        ['from' => 0.0, 'to' => 16.67],
        ['from' => 83.33, 'to' => 100.0],
    ]);
});

it('shades the whole width under a lone rainy window, as the trace spans it', function (): void {
    expect(RainMarks::stretches([true]))->toBe([['from' => 0.0, 'to' => 100.0]]);
});

it('marks nothing without rain or without a spectrum', function (): void {
    expect(RainMarks::stretches([false, false]))->toBe([])
        ->and(RainMarks::stretches([null, null]))->toBe([])
        ->and(RainMarks::stretches([]))->toBe([]);
});
