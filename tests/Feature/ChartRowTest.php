<?php

declare(strict_types=1);

use App\ValueObject\ChartRow;
use App\ValueObject\NoiseWindow;

/** 08:00 UTC on 24.9.2026, 10:00 in Prague. */
const CHART_ROW_BUCKET = 1_790_236_800;

/** The same slot as milliseconds of local wall-clock time. */
const CHART_ROW_WALL_CLOCK_MS = 1_790_244_000_000;

/**
 * @return object{bucket: int, t_avg: string|null, h_avg: string|null, p_avg: string|null, t_min: string|null, t_max: string|null, h_min: string|null, h_max: string|null, p_min: string|null, p_max: string|null}
 */
function chartRowBucket(?string $temperature = '1250.4', ?string $humidity = '6000', ?string $pressure = '97000.2'): object
{
    return (object) [
        'bucket' => CHART_ROW_BUCKET,
        't_avg' => $temperature, 'h_avg' => $humidity, 'p_avg' => $pressure,
        't_min' => '1210', 't_max' => '1290',
        'h_min' => '5800', 'h_max' => '6200',
        'p_min' => '96990', 'p_max' => '97010',
    ];
}

/** A spectrum of 26 bands, each 45.5 dB, as the query returns it. */
function chartRowSpectrum(): string
{
    return json_encode(array_fill(0, NoiseWindow::BANDS_COUNT, 4550), JSON_THROW_ON_ERROR);
}

/**
 * @return object{bucket: int, laeq: string|null, la10: string|null, la90: string|null, lamax: string|null, bands: string|null}
 */
function chartRowNoise(?string $laeq, ?string $bands): object
{
    return (object) [
        'bucket' => CHART_ROW_BUCKET,
        'laeq' => $laeq, 'la10' => '6510', 'la90' => '5049', 'lamax' => '8100',
        'bands' => $bands,
    ];
}

it('turns a bucket into a row of means, extremes and the figures derived from them', function (): void {
    expect(ChartRow::bucket(chartRowBucket()))->toBe([
        CHART_ROW_WALL_CLOCK_MS, 12.5, 60.0, 1010.7, 4.94, CHART_ROW_BUCKET,
        12.1, 12.9, 58.0, 62.0, 1010.59, 1010.8,
    ]);
});

it('leaves a hole for a slot without a full mean', function (?string $temperature, ?string $humidity, ?string $pressure): void {
    $row = ChartRow::bucket(chartRowBucket($temperature, $humidity, $pressure));

    expect($row)->toBe([CHART_ROW_WALL_CLOCK_MS, null, null, null, null, CHART_ROW_BUCKET, null, null, null, null, null, null])
        ->toHaveCount(12);
})->with([
    'no temperature' => [null, '6000', '97000.2'],
    'no humidity' => ['1250.4', null, '97000.2'],
    'no pressure' => ['1250.4', '6000', null],
]);

it('turns a noise bucket into a row of levels in tenths of a dB', function (): void {
    $row = ChartRow::noise(chartRowNoise('6234', chartRowSpectrum()));

    expect($row)->toHaveCount(2 + 4 + NoiseWindow::BANDS_COUNT)
        ->and(array_slice($row, 0, 7))->toBe([CHART_ROW_WALL_CLOCK_MS, CHART_ROW_BUCKET, 62.3, 65.1, 50.5, 81.0, 45.5])
        ->and(array_unique(array_slice($row, 6)))->toBe([0 => 45.5]);
});

it('pads a noise slot without a spectrum to the full width with nulls', function (?string $laeq, ?string $bands): void {
    $row = ChartRow::noise(chartRowNoise($laeq, $bands));

    expect($row)->toBe([CHART_ROW_WALL_CLOCK_MS, CHART_ROW_BUCKET, ...array_fill(0, 4 + NoiseWindow::BANDS_COUNT, null)]);
})->with([
    'no LAeq' => [null, chartRowSpectrum()],
    'no bands' => ['6234', null],
]);

it('turns a light bucket into lux with two decimals', function (): void {
    $row = ChartRow::light((object) ['bucket' => CHART_ROW_BUCKET, 'l_avg' => '12345.6667', 'l_min' => 1, 'l_max' => '4500000']);

    expect($row)->toBe([CHART_ROW_WALL_CLOCK_MS, CHART_ROW_BUCKET, 123.46, 0.01, 45000.0]);
});

it('leaves a light slot without light as nulls', function (): void {
    $row = ChartRow::light((object) ['bucket' => CHART_ROW_BUCKET, 'l_avg' => null, 'l_min' => null, 'l_max' => null]);

    expect($row)->toBe([CHART_ROW_WALL_CLOCK_MS, CHART_ROW_BUCKET, null, null, null]);
});
