<?php

declare(strict_types=1);

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Queries\CachedForecastAccuracy;
use App\ValueObject\DayScore;
use App\ValueObject\HourOfDayScore;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\ScoreFigures;
use App\ValueObject\TodaySlot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * A forecast for the next hour at 12.8 °C, issued from readings of 12 °C, that came true at 13 °C.
 */
function cachedAccuracyForecast(Sensor $sensor, int $issuedAt): void
{
    foreach ([$issuedAt => 1200, $issuedAt + 3600 => 1300] as $timestamp => $temperature) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => $timestamp,
            'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: 97000),
        ]);
    }

    Forecast::factory()->for($sensor)->create([
        'issued_at' => $issuedAt,
        'data' => [[
            'hours' => 1,
            'temperature' => ['low' => 12.0, 'mid' => 12.8, 'high' => 13.5],
            'humidity' => ['low' => 70.0, 'mid' => 75.0, 'high' => 80.0],
            'pressure' => ['low' => 976.0, 'mid' => 976.5, 'high' => 977.0],
            'rain_probability' => 0.0,
        ]],
    ]);
}

/**
 * @return array<int, int>
 */
function cachedAccuracyCounts(Sensor $sensor): array
{
    $scores = new CachedForecastAccuracy($sensor->id)->lastDays(7);

    return array_column(array_map(fn (array $score): array => [$score['hours'], $score['shown']->count], $scores), 1, 0);
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 12:00:00', 'UTC'));
});

it('has nothing to score without forecasts of the sensor', function (): void {
    $sensor = Sensor::factory()->create();
    cachedAccuracyForecast(Sensor::factory()->create(), Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp());

    expect(new CachedForecastAccuracy($sensor->id)->lastDays(7))->toBe([]);
});

it('scores the forecasts of the last days', function (): void {
    $sensor = Sensor::factory()->create();
    cachedAccuracyForecast($sensor, Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp());

    expect(cachedAccuracyCounts($sensor))->toBe([1 => 1]);
});

it('reuses the scores while the newest forecast is the one they were scored at', function (): void {
    $sensor = Sensor::factory()->create();
    $issuedAt = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    cachedAccuracyForecast($sensor, $issuedAt);
    cachedAccuracyCounts($sensor);

    // An older forecast that has come true too: scoring again would count it.
    cachedAccuracyForecast($sensor, $issuedAt - 7200);

    expect(cachedAccuracyCounts($sensor))->toBe([1 => 1]);
});

it('scores again when a newer forecast arrives', function (): void {
    $sensor = Sensor::factory()->create();
    $issuedAt = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    cachedAccuracyForecast($sensor, $issuedAt);
    cachedAccuracyCounts($sensor);

    cachedAccuracyForecast($sensor, $issuedAt + 600);

    expect(cachedAccuracyCounts($sensor))->toBe([1 => 2]);
});

it('ignores scores cached in another shape', function (): void {
    $sensor = Sensor::factory()->create();
    $issuedAt = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    cachedAccuracyForecast($sensor, $issuedAt);
    Cache::put("forecast-accuracy:{$sensor->id}", ['shape' => 0, 'issuedAt' => $issuedAt, 'days' => 7, 'scores' => ['stale']], now()->addMinutes(15));

    expect(cachedAccuracyCounts($sensor))->toBe([1 => 1]);
});

it('does not hand one span the scores cached for another', function (): void {
    $sensor = Sensor::factory()->create();
    $issuedAt = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    cachedAccuracyForecast($sensor, $issuedAt - 2 * 86_400);
    cachedAccuracyForecast($sensor, $issuedAt);
    new CachedForecastAccuracy($sensor->id)->lastDays(1);

    expect(cachedAccuracyCounts($sensor))->toBe([1 => 2]);
});

it('reads the scores back from the database store as value objects', function (): void {
    config(['cache.default' => 'database']);
    $sensor = Sensor::factory()->create();
    cachedAccuracyForecast($sensor, Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp());
    $scored = new CachedForecastAccuracy($sensor->id)->lastDays(7);

    $cached = Cache::get("forecast-accuracy:{$sensor->id}")['scores'][0];

    expect($cached['days'][0])->toBeInstanceOf(DayScore::class)->toEqual($scored[0]['days'][0])
        ->and($cached['byHour']['month'][0])->toBeInstanceOf(HourOfDayScore::class)
        ->and($cached['today'][0] ?? null)->toBeInstanceOf(TodaySlot::class)
        ->and($cached['shown'])->toBeInstanceOf(ScoreFigures::class);
});
