<?php

declare(strict_types=1);

use App\ValueObject\ForecastChart;

const CHART_NOW = 1_790_000_000;

/**
 * An hour of a forecast issued at $issuedAt, by default from the newest reading.
 *
 * @return array{hours: int, at: int, clock: string, t: float, tLow: float, tHigh: float, rain: int}
 */
function chartHour(int $hours, float $t, float $low, float $high, int $rain = 5, int $issuedAt = CHART_NOW): array
{
    return ['hours' => $hours, 'at' => $issuedAt + $hours * 3600, 'clock' => sprintf('%02d:00', 14 + $hours), 't' => $t, 'tLow' => $low, 'tHigh' => $high, 'rain' => $rain];
}

function sampleChart(): ForecastChart
{
    $now = CHART_NOW;

    return ForecastChart::of(
        [['at' => $now - 6 * 3600, 't' => 10.0], ['at' => $now - 3 * 3600, 't' => 12.0], ['at' => $now, 't' => 14.0]],
        [chartHour(3, 15.0, 14.0, 16.0), chartHour(6, 12.0, 10.0, 14.0)],
    );
}

it('puts the measured hours on the left half and the forecast on the right', function (): void {
    $chart = sampleChart();

    expect(array_column($chart->measured, 'x'))->toBe([0.0, 25.0, 50.0])
        ->and(array_column($chart->median, 'x'))->toBe([50.0, 75.0, 100.0])
        ->and($chart->now())->toBe($chart->measured[2]);
});

it('starts the median and its range at the newest reading', function (): void {
    $chart = sampleChart();

    expect($chart->high[0])->toBe($chart->now())
        ->and($chart->low[0])->toBe($chart->now())
        ->and($chart->band())->toStartWith('M50,')->toEndWith('Z')
        ->and($chart->medianLine())->toStartWith('M50,');
});

it('lays every line on one scale, warmer higher up', function (): void {
    $chart = sampleChart();

    // 16 °C, the top of the range, sits above the 14 °C now, and 10 °C below it.
    expect($chart->high[1]['y'])->toBeLessThan($chart->now()['y'])
        ->and($chart->measured[0]['y'])->toBeGreaterThan($chart->now()['y'])
        ->and($chart->measured[0]['y'])->toBe($chart->low[2]['y']);
});

it('labels the axis on whole degrees inside the box', function (): void {
    $ticks = sampleChart()->ticks;

    expect(array_column($ticks, 'value'))->toBe([10.0, 12.0, 14.0, 16.0]);

    foreach ($ticks as $tick) {
        expect($tick['y'])->toBeGreaterThan(0.0)->toBeLessThan(100.0);
    }
});

it('pins a reading older than six hours to the left edge', function (): void {
    $chart = ForecastChart::of(
        [['at' => 0, 't' => 9.0], ['at' => 7 * 3600, 't' => 10.0]],
        [chartHour(1, 10.5, 10.0, 11.0)],
    );

    expect($chart->measured[0]['x'])->toBe(0.0);
});

it('carries each hour\'s clock, median and rain chance for the labels', function (): void {
    expect(sampleChart()->hours[0])->toMatchArray(['x' => 75.0, 'clock' => '17:00', 't' => 15.0, 'rain' => 5]);
});

it('places each hour by its own time when the forecast started before the newest reading', function (): void {
    // Issued half an hour before the newest reading: one hour ahead is half an hour from now.
    $chart = ForecastChart::of(
        [['at' => CHART_NOW - 3600, 't' => 10.0], ['at' => CHART_NOW, 't' => 11.0]],
        [chartHour(1, 12.0, 11.0, 13.0, issuedAt: CHART_NOW - 1800)],
    );

    expect($chart->hours[0]['x'])->toBe(54.17)
        ->and($chart->median[1]['x'])->toBe(54.17);
});
