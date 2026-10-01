<?php

declare(strict_types=1);

use App\ValueObject\DayFigures;

/**
 * @return array{t: float, h: float, p: float, tMin: float, tMax: float, hMin: float, hMax: float, pMin: float, pMax: float, n: ?float, l: ?float, lMin: ?float, lMax: ?float}
 */
function dayEntry(float $temperature, ?float $tMin = null, ?float $tMax = null, ?float $noise = null, ?float $light = null, ?float $lightMin = null, ?float $lightMax = null): array
{
    return [
        't' => $temperature, 'h' => 60.0, 'p' => 1010.0,
        'tMin' => $tMin ?? $temperature - 1.0, 'tMax' => $tMax ?? $temperature + 1.0,
        'hMin' => 55.0, 'hMax' => 65.0,
        'pMin' => 1009.0, 'pMax' => 1011.0,
        'n' => $noise, 'l' => $light, 'lMin' => $lightMin, 'lMax' => $lightMax,
    ];
}

it('refuses a day without readings', function (): void {
    DayFigures::of([]);
})->throws(UnexpectedValueException::class, 'No readings to summarise.');

it('summarises temperature, humidity and pressure, and leaves out noise and light nobody measured', function (): void {
    $metrics = DayFigures::of([dayEntry(10.0), dayEntry(12.0)])->metrics();

    expect(array_keys($metrics))->toBe(['t', 'h', 'p'])
        ->and($metrics['t'])->toBe(['now' => 12.0, 'delta' => 2.0, 'dayMin' => 9.0, 'dayMax' => 13.0, 'trace' => [10.0, 12.0]])
        ->and($metrics['h'])->toMatchArray(['now' => 60.0, 'dayMin' => 55.0, 'dayMax' => 65.0])
        ->and($metrics['p'])->toMatchArray(['now' => 1010.0, 'dayMin' => 1009.0, 'dayMax' => 1011.0]);
});

it('measures the change against the entry an hour back, six slots before the newest', function (): void {
    $day = array_map(fn (int $step): array => dayEntry(10.0 + $step), range(0, 9));

    expect(DayFigures::of($day)->metrics()['t']['delta'])->toBe(6.0);
});

it('measures the change against the oldest entry when the day is shorter than an hour', function (int $length): void {
    $day = array_map(fn (int $step): array => dayEntry(10.0 + $step), range(0, $length - 1));

    expect(DayFigures::of($day)->metrics()['t']['delta'])->toBe((float) ($length - 1));
})->with([
    'a single entry' => [1],
    'three entries' => [3],
    'seven entries, the oldest exactly an hour back' => [7],
]);

it('takes the day\'s extremes off the entries\' extremes, not their means', function (): void {
    $metrics = DayFigures::of([
        dayEntry(10.0, tMin: 7.5, tMax: 10.5),
        dayEntry(14.0, tMin: 13.0, tMax: 16.5),
    ])->metrics();

    expect($metrics['t'])->toMatchArray(['dayMin' => 7.5, 'dayMax' => 16.5]);
});

it('summarises noise from the entries that heard some', function (): void {
    $metrics = DayFigures::of([
        dayEntry(10.0, noise: 45.5),
        dayEntry(10.0),
        dayEntry(10.0, noise: 52.0),
        dayEntry(10.0),
    ])->metrics();

    expect($metrics['n'])->toBe(['now' => 52.0, 'delta' => 6.5, 'dayMin' => 45.5, 'dayMax' => 52.0, 'trace' => [45.5, 52.0]]);
});

it('takes the day\'s light off the entries\' extremes', function (): void {
    $metrics = DayFigures::of([
        dayEntry(10.0, light: 200.0, lightMin: 150.0, lightMax: 900.0),
        dayEntry(10.0, light: 400.0, lightMin: 380.0, lightMax: 450.0),
    ])->metrics();

    expect($metrics['l'])->toBe(['now' => 400.0, 'delta' => 200.0, 'dayMin' => 150.0, 'dayMax' => 900.0, 'trace' => [200.0, 400.0]]);
});

it('falls back to the light mean for an entry that kept no extremes', function (): void {
    $metrics = DayFigures::of([
        dayEntry(10.0, light: 200.0),
        dayEntry(10.0, light: 400.0),
    ])->metrics();

    expect($metrics['l'])->toMatchArray(['dayMin' => 200.0, 'dayMax' => 400.0]);
});
