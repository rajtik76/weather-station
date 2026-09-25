<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Queries\ForecastAccuracy;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\NoiseWindow;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * One stored horizon: the temperature band and the rain chance.
 *
 * @return array<string, mixed>
 */
function scoredHorizon(int $hours, float $low, float $mid, float $high, float $rain = 0.0): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $low, 'mid' => $mid, 'high' => $high],
        'humidity' => ['low' => 70.0, 'mid' => 75.0, 'high' => 80.0],
        'pressure' => ['low' => 976.0, 'mid' => 976.5, 'high' => 977.0],
        'rain_probability' => $rain,
    ];
}

function measuredAt(Sensor $sensor, int $timestamp, int $temperature): void
{
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: 97000),
    ]);
}

/** A V3 window at 12 °C whose spectrum RainDetector hears as rain, or as a dry street. */
function listenedAt(Sensor $sensor, int $timestamp, bool $raining): void
{
    $bands = array_fill(0, 26, 3000);
    $bands[15] = 4000;
    $bands[17] = 4000;
    $bands[16] = $raining ? 4500 : 4000;
    $bands[25] = $raining ? 5500 : 3000;

    Measurement::factory()->for($sensor)->v3()->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV3(
            temperature: 1200, humidity: 8000, pressure: 97000,
            temperatureMin: 1190, temperatureMax: 1210,
            humidityMin: 7900, humidityMax: 8100,
            pressureMin: 96990, pressureMax: 97010,
            samples: 20,
            noise: new NoiseWindow(seconds: 600, laeq: 6000, lamax: 7000, la10: 6200, la90: 5500, bands: $bands),
        ),
    ]);
}

/**
 * All 24 local hours, empty but for the given `[percent, count, grade, mean error, largest error]`.
 *
 * @param  array<int, array{0: float, 1: int, 2: string, 3: float, 4: float}>  $scored
 * @return list<array{0: ?float, 1: int, 2: ?string, 3: ?float, 4: ?float}>
 */
function byHour(array $scored): array
{
    return array_map(fn (int $hour): array => $scored[$hour] ?? [null, 0, null, null, null], range(0, 23));
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 12:00:00', 'UTC'));
});

it('scores each horizon against the window that came n hours later, by local hour', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    measuredAt($sensor, $issued + 7200, 1500);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => $issued,
        'data' => [
            scoredHorizon(1, 12.0, 12.8, 13.5),
            scoredHorizon(2, 12.5, 13.0, 14.0),
            // Nothing measured three hours on: not scored.
            scoredHorizon(3, 12.0, 13.0, 14.0),
        ],
    ]);

    // 09:00 and 10:00 UTC are 11:00 and 12:00 in Prague.
    expect(new ForecastAccuracy($sensor->id)->since($issued))->toEqual([
        ['hours' => 1, 'count' => 1, 'temperature' => 100.0, 'rainCount' => 0, 'rainCases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null, 'byHour' => byHour([11 => [100.0, 1, 'good', 0.2, 0.2]])],
        ['hours' => 2, 'count' => 1, 'temperature' => 0.0, 'rainCount' => 0, 'rainCases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null, 'byHour' => byHour([12 => [0.0, 1, 'poor', 2.0, 2.0]])],
    ]);
});

it('gives each local hour its mean and its largest miss', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    // Two forecasts ten minutes apart, both an hour ahead into 11:00 in Prague: 0.2 °C off, then 1.2 °C.
    foreach ([[$issued, 1300], [$issued + 600, 1400]] as [$at, $truth]) {
        measuredAt($sensor, $at, 1200);
        measuredAt($sensor, $at + 3600, $truth);
        Forecast::factory()->for($sensor)->create(['issued_at' => $at, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    }

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.byHour.11'))->toBe([50.0, 2, 'fair', 0.7, 1.2]);
});

it('scores rain by what the microphone heard within the hours ahead', function (): void {
    $sensor = Sensor::factory()->create();
    $wet = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    $dry = Date::parse('2026-09-24 10:00:00', 'UTC')->getTimestamp();

    foreach ([$wet => 3, $dry => null] as $issued => $rainySlot) {
        foreach (range(0, 6) as $slot) {
            listenedAt($sensor, $issued + $slot * 600, $slot === $rainySlot);
        }
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $wet, 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0, 0.4)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $dry, 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0, 0.06)]]);

    expect(new ForecastAccuracy($sensor->id)->since($wet))->sequence(
        fn ($score) => $score->toMatchArray(['hours' => 1, 'count' => 2, 'rainCount' => 2, 'rainCases' => 1, 'chanceWhenRain' => 40.0, 'chanceWhenDry' => 6.0]),
    );
});

it('leaves out rain the microphone did not listen through', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5, 0.5)]]);

    expect(new ForecastAccuracy($sensor->id)->since($issued))->sequence(
        fn ($score) => $score->toMatchArray(['count' => 1, 'rainCount' => 0, 'rainCases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null]),
    );
});

it('scores a forecast whose own window went unmeasured', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    // Nothing at 08:00; the hour ahead is all the score needs.
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.count'))->toBe(1);
});

it('reads a slot stamped twice by its first reading', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    // A drifting clock put two readings into the 09:00 slot; the service takes the first.
    measuredAt($sensor, $issued + 3600 + 2, 1300);
    measuredAt($sensor, $issued + 3600 + 598, 2000);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.temperature'))->toBe(100.0);
});

it('scores rain only on the hours whose temperature came true', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    // The microphone heard every window, but the reading at 09:00 is missing.
    foreach (range(0, 5) as $slot) {
        listenedAt($sensor, $issued + $slot * 600, $slot === 3);
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0, 0.4)]]);

    expect(new ForecastAccuracy($sensor->id)->since($issued))->toBe([]);
});

it('scores a horizon however far ahead the service forecasts', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-23 20:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 9 * 3600, 900);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(9, 8.0, 9.5, 11.0)]]);

    // 05:00 UTC on the next day, 07:00 in Prague.
    expect(new ForecastAccuracy($sensor->id)->since($issued))->sequence(
        fn ($score) => $score->toMatchArray(['hours' => 9, 'count' => 1, 'byHour' => byHour([7 => [100.0, 1, 'good', 0.5, 0.5]])]),
    );
});

it('scores only the sensor\'s own forecasts from the given time on', function (): void {
    $sensor = Sensor::factory()->create();
    $other = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    foreach ([$sensor, $other] as $station) {
        measuredAt($station, $issued, 1200);
        measuredAt($station, $issued + 3600, 1300);
    }

    Forecast::factory()->for($other)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.count'))->toBe(1)
        ->and(new ForecastAccuracy($sensor->id)->since($issued + 1))->toBe([]);
});

it('folds the accuracy into the forecast, closed until asked', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    // Nothing has come true yet.
    Livewire::test(Dashboard::class)->assertSee('Forecast · next 6 hours')->assertDontSee('Accuracy · last 7 days');

    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    $dashboard = Livewire::test(Dashboard::class);
    $dashboard->assertSeeInOrder(['id="forecast"', 'Accuracy · last 7 days', '+1 h', '100 %', 'not listened', '<td class="pt-1.5 pr-6">1</td>', 'data-accuracy-chart="1"'], false);

    expect($dashboard->html())
        ->toMatch('/aria-expanded="false"[^>]*aria-controls="forecast-accuracy"[^>]*aria-label="Expand Forecast accuracy"/')
        ->toContain('<div id="forecast-accuracy" class="hidden" x-bind:class="{ hidden: collapsed }">')
        ->toMatch('/text-emerald-600[^"]*">100 %/')
        // The row's chart opens by its icon, closed until then.
        ->toMatch('/aria-expanded="false"[^>]*aria-controls="forecast-accuracy-1"[^>]*aria-label="Show \\+1 h by hour of the day"/')
        ->toContain('<tr id="forecast-accuracy-1" class="hidden" x-bind:class="{ hidden: ! open }">')
        // The chart gets all 24 hours with their grade.
        ->toContain('data-accuracy-hours="'.e(json_encode(byHour([11 => [100.0, 1, 'good', 0.2, 0.2]]), JSON_THROW_ON_ERROR)).'"');
});

it('colours the temperature accuracy by its grade', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    // Out of range: 0 %.
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 14.0, 15.0, 16.0)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    expect(Livewire::test(Dashboard::class)->html())->toMatch('/text-red-600[^"]*">0 %/');
});

it('says which side of the rain score it has no case for', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    // It rained within every hour scored: there is no dry case to average.
    foreach (range(0, 6) as $slot) {
        listenedAt($sensor, $issued + $slot * 600, $slot === 2);
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0, 0.47)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    Livewire::test(Dashboard::class)->assertSeeInOrder(['Accuracy · last 7 days', '+1 h', '47 %', '/', 'no dry spell']);
});

it('shows no accuracy without a current forecast to fold it into', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    // Scored, but an hour older than the newest reading: the forecast block is gone.
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    Livewire::test(Dashboard::class)->assertDontSee('Forecast · next 6 hours')->assertDontSee('Accuracy · last 7 days');
});

it('keeps the score until the next forecast arrives', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0]['count'])->toBe(1);

    // A reading alone completes the second forecast's hour, but the score waits for the next forecast.
    measuredAt($sensor, $issued + 7200, 1300);
    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0]['count'])->toBe(1);

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);
    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0]['count'])->toBe(2);
});

it('keeps one score per sensor in the cache, however many forecasts come', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);
    Livewire::test(Dashboard::class);

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);
    Livewire::test(Dashboard::class);

    // The database store deletes an expired row only when it reads it; a key per forecast was never read again.
    expect(DB::table('cache')->count())->toBe(1);
})->with([fn () => config(['cache.default' => 'database'])]);
