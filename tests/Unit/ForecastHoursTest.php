<?php

declare(strict_types=1);

use App\ValueObject\ForecastHour;
use App\ValueObject\ForecastHours;
use App\ValueObject\IssuedForecast;

function hoursUtc(string $utc): int
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'))->getTimestamp();
}

/**
 * @return array{hours: int, temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}, pressure: array{low: float, mid: float, high: float}, rain_probability: float}
 */
function forecastHoursHorizon(int $hours, float $mid, float $rain = 0.0, float $spread = 1.0): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $mid - $spread, 'mid' => $mid, 'high' => $mid + $spread],
        'humidity' => ['low' => 60.0, 'mid' => 70.0, 'high' => 80.0],
        'pressure' => ['low' => 1009.0, 'mid' => 1010.0, 'high' => 1011.0],
        'rain_probability' => $rain,
    ];
}

/**
 * @param  non-empty-list<array{hours: int, temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}, pressure: array{low: float, mid: float, high: float}, rain_probability: float}>  $horizons
 */
function forecastIssuedAt(string $utc, array $horizons): IssuedForecast
{
    return new IssuedForecast(hoursUtc($utc), $horizons);
}

it('rounds the temperatures to tenths and the rain chance to a whole percent', function (): void {
    $horizon = forecastHoursHorizon(1, 12.36, 0.346);
    $horizon['temperature'] = ['low' => 11.04, 'mid' => 12.36, 'high' => 13.26];

    $hour = ForecastHours::of([forecastIssuedAt('2026-09-24 08:00:00', [$horizon])], [])[0];

    expect($hour)->toEqual(new ForecastHour(hours: 1, at: hoursUtc('2026-09-24 09:00:00'), clock: '11:00', t: 12.4, tLow: 11.0, tHigh: 13.3, rain: 35));
});

it('prints each hour\'s clock in local time, summer and winter', function (string $issued, array $clocks): void {
    $forecast = forecastIssuedAt($issued, [forecastHoursHorizon(1, 12.0), forecastHoursHorizon(3, 12.0)]);

    expect(array_column(ForecastHours::of([$forecast], []), 'clock'))->toBe($clocks);
})->with([
    'summer, UTC+2' => ['2026-09-24 08:00:00', ['11:00', '13:00']],
    'winter, UTC+1' => ['2026-12-21 08:00:00', ['10:00', '12:00']],
]);

it('lands an off-hour forecast on whole hours, from its reading towards the first horizon', function (): void {
    $forecast = forecastIssuedAt('2026-09-24 08:20:00', [forecastHoursHorizon(1, 16.0, 0.3), forecastHoursHorizon(2, 19.0, 0.6)]);
    $reading = ['at' => hoursUtc('2026-09-24 08:21:00'), 't' => 10.0];

    $hours = ForecastHours::of([$forecast], [$reading]);

    // 09:00 UTC is 39 of the 59 minutes from the reading to the first horizon, 10:00 two thirds of the way to the second.
    expect($hours)->sequence(
        fn ($hour) => $hour->toMatchObject(['hours' => 1, 'clock' => '11:00', 't' => 14.0, 'tLow' => 13.3, 'tHigh' => 14.6, 'rain' => 30]),
        fn ($hour) => $hour->toMatchObject(['hours' => 2, 'clock' => '12:00', 't' => 18.0, 'tLow' => 17.0, 'tHigh' => 19.0, 'rain' => 50]),
    );
});

it('keeps the widest range and rain chance of the hour around the newest median', function (): void {
    $hours = ForecastHours::of([
        forecastIssuedAt('2026-09-24 08:00:00', [forecastHoursHorizon(1, 17.0, 0.4, 2.0)]),
        forecastIssuedAt('2026-09-24 08:10:00', [forecastHoursHorizon(1, 16.5, 0.1, 0.5), forecastHoursHorizon(2, 16.5, 0.1, 0.5)]),
    ], []);

    expect($hours[0])->toMatchObject(['clock' => '11:00', 't' => 16.5, 'tLow' => 15.0, 'tHigh' => 19.0, 'rain' => 40]);
});

it('leaves out an hour already measured when the newest forecast is from the previous hour', function (): void {
    $forecast = forecastIssuedAt('2026-09-24 07:50:00', [forecastHoursHorizon(1, 12.0), forecastHoursHorizon(2, 13.0)]);
    $newest = ['at' => hoursUtc('2026-09-24 08:20:00'), 't' => 11.0];

    expect(array_column(ForecastHours::of([$forecast], [$newest]), 'clock'))->toBe(['11:00']);
});

it('has no hours without a forecast', function (): void {
    expect(ForecastHours::of([], [['at' => hoursUtc('2026-09-24 08:20:00'), 't' => 11.0]]))->toBe([]);
});

it('refuses a forecast without horizons', function (): void {
    new IssuedForecast(hoursUtc('2026-09-24 08:00:00'), []);
})->throws(InvalidArgumentException::class);

it('names each hour by the model shown on the horizon nearest to it', function (): void {
    $forecast = forecastIssuedAt('2026-10-10 08:50:00', [
        [...forecastHoursHorizon(1, 12.0), 'shown_by' => 'light-v6'],
        [...forecastHoursHorizon(2, 13.0), 'shown_by' => 'light-v5'],
        [...forecastHoursHorizon(3, 14.0), 'shown_by' => 'base'],
    ]);

    expect(array_column(ForecastHours::of([$forecast], []), 'model', 'clock'))->toBe(['11:00' => 'light-v6', '12:00' => 'light-v6', '13:00' => 'light-v5']);
});
