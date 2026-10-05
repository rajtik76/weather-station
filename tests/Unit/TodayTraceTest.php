<?php

declare(strict_types=1);

use App\ValueObject\TodaySlot;
use App\ValueObject\TodayTrace;

/**
 * @param  array{version: string, temperature: array{low: float, mid: float, high: float}}|null  $experiment
 * @return array{hours: int, temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}, pressure: array{low: float, mid: float, high: float}, rain_probability: float, base?: array{temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}}, experiment?: array{version: string, temperature: array{low: float, mid: float, high: float}}}
 */
function tracedHorizon(int $hours, float $mid, ?float $base = null, ?array $experiment = null): array
{
    $band = ['low' => $mid - 1, 'mid' => $mid, 'high' => $mid + 1];

    return [
        'hours' => $hours,
        'temperature' => $band,
        'humidity' => $band,
        'pressure' => $band,
        'rain_probability' => 0.0,
        ...($base === null ? [] : ['base' => ['temperature' => ['low' => $base - 1, 'mid' => $base, 'high' => $base + 1], 'humidity' => $band]]),
        ...($experiment === null ? [] : ['experiment' => $experiment]),
    ];
}

it('pairs each slot since local midnight with the forecast issued one horizon before it', function (): void {
    // 22:00 UTC is midnight in Prague.
    $midnight = new DateTimeImmutable('2026-09-23 22:00:00', new DateTimeZone('UTC'))->getTimestamp();
    $forecasts = [
        $midnight - 3600 => [tracedHorizon(2, 9.0), tracedHorizon(1, 12.0, base: 11.5, experiment: ['version' => 'light-v2', 'temperature' => ['low' => 11.0, 'mid' => 12.2, 'high' => 13.0]])],
        $midnight - 3000 => [tracedHorizon(1, 12.4, experiment: ['version' => 'light-v1', 'temperature' => ['low' => 11.0, 'mid' => 12.6, 'high' => 13.0]])],
    ];

    $slots = TodayTrace::of(1, $forecasts, [$midnight => 11.9, $midnight + 1200 => 11.7], $midnight + 1200, 'light-v2');

    expect($slots)->toEqual([
        new TodaySlot($midnight, '00:00', 11.9, ['low' => 11.0, 'mid' => 12.0, 'high' => 13.0], 11.5, 12.2),
        new TodaySlot($midnight + 600, '00:10', null, ['low' => 11.4, 'mid' => 12.4, 'high' => 13.4]),
        new TodaySlot($midnight + 1200, '00:20', 11.7),
    ]);
});
