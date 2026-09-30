<?php

declare(strict_types=1);

use App\Models\Forecast;
use App\ValueObject\ForecastHours;
use Illuminate\Support\Facades\Date;

/**
 * One stored horizon around the given median, a degree either side.
 *
 * @return array<string, mixed>
 */
function forecastHoursHorizon(int $hours, float $mid, float $rain = 0.0): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $mid - 1.0, 'mid' => $mid, 'high' => $mid + 1.0],
        'humidity' => ['low' => 60.0, 'mid' => 70.0, 'high' => 80.0],
        'pressure' => ['low' => 1009.0, 'mid' => 1010.0, 'high' => 1011.0],
        'rain_probability' => $rain,
    ];
}

/**
 * @param  list<array<string, mixed>>  $data
 */
function forecastIssuedAt(string $utc, array $data): Forecast
{
    return new Forecast(['issued_at' => Date::parse($utc, 'UTC')->getTimestamp(), 'data' => $data]);
}

it('trends each hour against the one before it, the first against the newest reading', function (): void {
    $forecast = forecastIssuedAt('2026-09-24 08:00:00', [
        forecastHoursHorizon(1, 12.5),
        // Against the newest reading this would be rising; against the hour before it holds.
        forecastHoursHorizon(2, 12.4),
        forecastHoursHorizon(3, 11.8),
    ]);

    expect(array_column(ForecastHours::of($forecast, 12.0), 'trend'))->toBe(['rising', 'steady', 'falling']);
});

it('reads the first hour as steady without a newest reading', function (): void {
    $forecast = forecastIssuedAt('2026-09-24 08:00:00', [forecastHoursHorizon(1, 25.0), forecastHoursHorizon(2, 20.0)]);

    expect(array_column(ForecastHours::of($forecast, null), 'trend'))->toBe(['steady', 'falling']);
});

it('calls a change of three tenths a trend and anything under it steady', function (float $mid, string $trend): void {
    $forecast = forecastIssuedAt('2026-09-24 08:00:00', [forecastHoursHorizon(1, $mid)]);

    expect(ForecastHours::of($forecast, 12.0)[0]['trend'])->toBe($trend);
})->with([
    'up three tenths' => [12.3, 'rising'],
    'up just under' => [12.29, 'steady'],
    'unchanged' => [12.0, 'steady'],
    'down just under' => [11.71, 'steady'],
    'down three tenths' => [11.7, 'falling'],
]);

it('rounds the temperatures to tenths and the rain chance to a whole percent', function (): void {
    $horizon = forecastHoursHorizon(1, 12.36, 0.346);
    $horizon['temperature'] = ['low' => 11.04, 'mid' => 12.36, 'high' => 13.26];

    $hour = ForecastHours::of(forecastIssuedAt('2026-09-24 08:00:00', [$horizon]), 12.0)[0];

    expect($hour)->toMatchArray(['hours' => 1, 't' => 12.4, 'tLow' => 11.0, 'tHigh' => 13.3, 'rain' => 35]);
});

it('draws the sky from the rounded rain chance', function (): void {
    $hour = ForecastHours::of(forecastIssuedAt('2026-09-24 08:00:00', [forecastHoursHorizon(1, 12.0, 0.596)]), 12.0)[0];

    expect($hour)->toMatchArray(['rain' => 60, 'sky' => ['icon' => 'cloud-rain', 'label' => 'rain likely', 'tone' => 'rain']]);
});

it('prints each hour\'s clock in local time, summer and winter', function (string $issued, array $clocks): void {
    $forecast = forecastIssuedAt($issued, [forecastHoursHorizon(1, 12.0), forecastHoursHorizon(3, 12.0)]);

    expect(array_column(ForecastHours::of($forecast, 12.0), 'clock'))->toBe($clocks);
})->with([
    'summer, UTC+2' => ['2026-09-24 08:00:00', ['11:00', '13:00']],
    'winter, UTC+1' => ['2026-12-21 08:00:00', ['10:00', '12:00']],
]);
