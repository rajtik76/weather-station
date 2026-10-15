<?php

declare(strict_types=1);

use App\ValueObject\TemperatureTrace;

it('breaks the shade trace at missing estimates and long data gaps', function (): void {
    $trace = new TemperatureTrace([
        ['timestamp' => 0, 'measured' => 12.0, 'estimated' => 10.0, 'reference' => null],
        ['timestamp' => 600, 'measured' => 12.0, 'estimated' => 11.0, 'reference' => null],
        ['timestamp' => 1200, 'measured' => 12.0, 'estimated' => null, 'reference' => null],
        ['timestamp' => 1800, 'measured' => 12.0, 'estimated' => 11.0, 'reference' => null],
        ['timestamp' => 3600, 'measured' => 12.0, 'estimated' => 10.0, 'reference' => null],
    ]);

    expect($trace->line(10.0, 12.0))->toBe('M0,92L25,50M75,50M100,92')
        ->and($trace->low(12.0))->toBe(10.0);
});

it('does not present a previous estimate as the current one', function (): void {
    $trace = new TemperatureTrace([
        ['timestamp' => 0, 'measured' => 12.0, 'estimated' => 10.0, 'reference' => null],
        ['timestamp' => 600, 'measured' => 12.0, 'estimated' => null, 'reference' => null],
    ]);

    expect($trace->latest())->toBeNull();
});
