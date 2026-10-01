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

it('rounds the temperatures to tenths and the rain chance to a whole percent', function (): void {
    $horizon = forecastHoursHorizon(1, 12.36, 0.346);
    $horizon['temperature'] = ['low' => 11.04, 'mid' => 12.36, 'high' => 13.26];

    $hour = ForecastHours::of(forecastIssuedAt('2026-09-24 08:00:00', [$horizon]))[0];

    expect($hour)->toMatchArray(['hours' => 1, 't' => 12.4, 'tLow' => 11.0, 'tHigh' => 13.3, 'rain' => 35]);
});

it('prints each hour\'s clock in local time, summer and winter', function (string $issued, array $clocks): void {
    $forecast = forecastIssuedAt($issued, [forecastHoursHorizon(1, 12.0), forecastHoursHorizon(3, 12.0)]);

    expect(array_column(ForecastHours::of($forecast), 'clock'))->toBe($clocks);
})->with([
    'summer, UTC+2' => ['2026-09-24 08:00:00', ['11:00', '13:00']],
    'winter, UTC+1' => ['2026-12-21 08:00:00', ['10:00', '12:00']],
]);
