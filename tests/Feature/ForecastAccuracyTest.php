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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * One stored horizon: the temperature band, the rain chance, and the base
 * model's temperature band as `[low, mid, high]` when the service sent one.
 *
 * @param  array{0: float, 1: float, 2: float}|null  $base
 * @return array<string, mixed>
 */
function scoredHorizon(int $hours, float $low, float $mid, float $high, float $rain = 0.0, ?array $base = null): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $low, 'mid' => $mid, 'high' => $high],
        'humidity' => ['low' => 70.0, 'mid' => 75.0, 'high' => 80.0],
        'pressure' => ['low' => 976.0, 'mid' => 976.5, 'high' => 977.0],
        'rain_probability' => $rain,
        ...($base === null ? [] : ['base' => [
            'temperature' => ['low' => $base[0], 'mid' => $base[1], 'high' => $base[2]],
            'humidity' => ['low' => 68.0, 'mid' => 75.0, 'high' => 82.0],
        ]]),
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
 * All 24 local hours, empty but for the given `[percent, count, mean error, largest error, mean signed error]`.
 *
 * @param  array<int, array{0: float, 1: int, 2: float, 3: float, 4: float}>  $scored
 * @return list<array{0: ?float, 1: int, 2: ?float, 3: ?float, 4: ?float}>
 */
function byHour(array $scored): array
{
    $hours = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $hours[] = $scored[$hour] ?? [null, 0, null, null, null];
    }

    return $hours;
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 12:00:00', 'UTC'));
});

/**
 * A day with nothing scored.
 *
 * @return list<string|int|null>
 */
function emptyDay(string $date, ?string $tookOver = null, ?int $correction = null): array
{
    return [$date, 0, null, null, null, null, null, null, null, null, null, $tookOver, $correction];
}

it('scores each horizon against the window that came n hours later, overall, by day and by local hour', function (): void {
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

    // Off by 0.2 °C where the naive 12 °C was off by 1: 80 % better. Off by 2 against 3: 33 %.
    // 09:00 and 10:00 UTC are 11:00 and 12:00 in Prague. Stored without a base: none to score.
    expect(new ForecastAccuracy($sensor->id)->since($issued))->toEqual([
        [
            'hours' => 1,
            'days' => [['24.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, null, null, null, null, null, null]],
            'corrected' => ['count' => 1, 'skill' => 80.0, 'error' => 0.2, 'naive' => 1.0, 'inRange' => 100.0, 'width' => 1.5],
            'base' => null,
            'rain' => ['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
            'byHour' => byHour([11 => [100.0, 1, 0.2, 0.2, 0.2]]),
        ],
        [
            'hours' => 2,
            'days' => [['24.9.2026', 1, 33.0, 2.0, 3.0, 0.0, 1.5, null, null, null, null, null, null]],
            'corrected' => ['count' => 1, 'skill' => 33.0, 'error' => 2.0, 'naive' => 3.0, 'inRange' => 0.0, 'width' => 1.5],
            'base' => null,
            'rain' => ['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
            'byHour' => byHour([12 => [0.0, 1, 2.0, 2.0, 2.0]]),
        ],
    ]);
});

it('scores the base model on the same hours, beside the forecast shown', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    // Before the correction: off by 0.6 against the guess's 1, and 13 °C outside its range.
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5, base: [12.0, 12.4, 12.9])]]);

    expect(new ForecastAccuracy($sensor->id)->since($issued))->sequence(
        fn ($score) => $score
            ->corrected->toBe(['count' => 1, 'skill' => 80.0, 'error' => 0.2, 'naive' => 1.0, 'inRange' => 100.0, 'width' => 1.5])
            ->base->toBe(['count' => 1, 'skill' => 40.0, 'error' => 0.6, 'naive' => 1.0, 'inRange' => 0.0, 'width' => 0.9])
            ->days->toBe([['24.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, 40.0, 0.6, 0.0, 0.9, null, null]])
            // The hour of day charts the forecast shown.
            ->byHour->toBe(byHour([11 => [100.0, 1, 0.2, 0.2, 0.2]])),
    );
});

it('compares the two on the hours that have a base, not the shown forecast on more', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    // The first forecast is from before the service returned a base; it missed by 2.
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1400);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0)]]);
    measuredAt($sensor, $issued + 600, 1200);
    measuredAt($sensor, $issued + 4200, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 600, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5, base: [12.0, 12.4, 12.9])]]);

    expect(new ForecastAccuracy($sensor->id)->since($issued))->sequence(
        fn ($score) => $score
            ->corrected->toMatchArray(['count' => 1, 'skill' => 80.0, 'error' => 0.2])
            ->base->toMatchArray(['count' => 1, 'skill' => 40.0, 'error' => 0.6])
            ->days->toBe([['24.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, 40.0, 0.6, 0.0, 0.9, null, null]])
            // The hour of day is not a comparison: every forecast shown.
            ->byHour->{11}->toBe([50.0, 2, 1.1, 2.0, 1.1]),
    );
});

it('marks a model that took over before any of its forecasts came true', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-23 08:00:00', 'UTC')->getTimestamp();
    $switched = Date::parse('2026-09-24 11:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'model' => '2026-09-20T08:00:00+00:00', 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    // An hour ago: nothing it forecast has come true yet.
    Forecast::factory()->for($sensor)->create(['issued_at' => $switched, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.days'))->toBe([
        ['23.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, null, null, null, null, null, null],
        emptyDay('24.9.2026', '24.9.2026 10:40'),
    ]);
});

it('sums the misses before it compares them, so a calm hour weighs less than a front', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    // Off by 0.5 against the guess's 1, then by 2.5 against 3: 3 against 4 is 25 % better, not the 33 % the two ratios average to.
    foreach ([[$issued, 1300, 12.5], [$issued + 600, 1500, 12.5]] as [$at, $truth, $mid]) {
        measuredAt($sensor, $at, 1200);
        measuredAt($sensor, $at + 3600, $truth);
        Forecast::factory()->for($sensor)->create(['issued_at' => $at, 'data' => [scoredHorizon(1, 11.0, $mid, 14.0)]]);
    }

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.days'))
        ->toBe([['24.9.2026', 2, 25.0, 1.5, 2.0, 50.0, 3.0, null, null, null, null, null, null]]);
});

it('marks the day a new model took over, even when its first forecast was not scored, and leaves a day without forecasts empty', function (): void {
    $sensor = Sensor::factory()->create();
    $before = Date::parse('2026-09-21 08:00:00', 'UTC')->getTimestamp();
    $switched = Date::parse('2026-09-22 08:00:00', 'UTC')->getTimestamp();
    $after = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    foreach ([$before, $after] as $issued) {
        measuredAt($sensor, $issued, 1200);
        measuredAt($sensor, $issued + 3600, 1300);
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $before, 'model' => '2026-09-20T08:00:00+00:00', 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0)]]);
    // Nothing was measured an hour after: the new model's first forecast is not scored, but it took over that day.
    Forecast::factory()->for($sensor)->create(['issued_at' => $switched, 'data' => [scoredHorizon(1, 11.0, 12.0, 13.0)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $after, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($before), '0.days'))->toBe([
        ['21.9.2026', 1, 0.0, 1.0, 1.0, 100.0, 2.0, null, null, null, null, null, null],
        emptyDay('22.9.2026', '24.9.2026 10:40'),
        emptyDay('23.9.2026'),
        ['24.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, null, null, null, null, null, null],
    ]);
});

it('marks the day a new version of the correction took over, and skips forecasts stored without one', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-23 08:00:00', 'UTC')->getTimestamp();
    $switched = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    foreach ([$issued, $switched] as $at) {
        measuredAt($sensor, $at, 1200);
        measuredAt($sensor, $at + 3600, 1300);
    }

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'correction' => 1, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    // Stored before versions were kept: neither a change nor the end of one.
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 600, 'correction' => null, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $switched, 'correction' => 2, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.days'))->toBe([
        ['23.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, null, null, null, null, null, null],
        ['24.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, null, null, null, null, null, 2],
    ]);
});

it('names a model that is not stamped with its training time as it came', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 600, 'model' => 'v3.4.0', 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.days.0.11'))->toBe('v3.4.0');
});

it('gives each local hour its mean and largest miss, and which way it went', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();

    // Two forecasts ten minutes apart, both an hour ahead into 11:00 in Prague: 0.2 °C warmer, then 1.2 °C colder.
    foreach ([[$issued, 1300], [$issued + 600, 1160]] as [$at, $truth]) {
        measuredAt($sensor, $at, 1200);
        measuredAt($sensor, $at + 3600, $truth);
        Forecast::factory()->for($sensor)->create(['issued_at' => $at, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    }

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.byHour.11'))->toBe([50.0, 2, 0.7, 1.2, -0.5]);
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

    expect(data_get(new ForecastAccuracy($sensor->id)->since($wet), '0.rain'))
        ->toBe(['count' => 2, 'cases' => 1, 'chanceWhenRain' => 40.0, 'chanceWhenDry' => 6.0]);
});

it('leaves out rain the microphone did not listen through', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5, 0.5)]]);

    expect(new ForecastAccuracy($sensor->id)->since($issued))->sequence(
        fn ($score) => $score
            ->corrected->toMatchArray(['count' => 1])
            ->rain->toBe(['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null]),
    );
});

it('scores a forecast whose own window went unmeasured, but not against the naive guess', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    // Nothing at 08:00: there is no guess to beat, but the range still came true.
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.corrected'))
        ->toMatchArray(['count' => 1, 'skill' => null, 'error' => null, 'naive' => null, 'inRange' => 100.0]);
});

it('has no skill where the naive guess never missed', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1200);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 11.5, 12.2, 13.0)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.corrected'))
        ->toMatchArray(['skill' => null, 'error' => 0.2, 'naive' => 0.0]);
});

it('reads a slot stamped twice by its first reading', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    // A drifting clock put two readings into the 09:00 slot; the service takes the first.
    measuredAt($sensor, $issued + 3600 + 2, 1300);
    measuredAt($sensor, $issued + 3600 + 598, 2000);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.corrected.inRange'))->toBe(100.0);
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
        fn ($score) => $score->toMatchArray(['hours' => 9, 'byHour' => byHour([7 => [100.0, 1, 0.5, 0.5, -0.5]])]),
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

    expect(data_get(new ForecastAccuracy($sensor->id)->since($issued), '0.corrected.count'))->toBe(1)
        ->and(new ForecastAccuracy($sensor->id)->since($issued + 1))->toBe([]);
});

it('folds the accuracy into the forecast, closed until asked', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    // Nothing has come true yet.
    Livewire::test(Dashboard::class)->assertSee('Forecast · next 6 hours')->assertDontSee('Accuracy · last 30 days');

    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    $dashboard = Livewire::test(Dashboard::class);
    $dashboard->assertSeeInOrder([
        'id="forecast"', 'Accuracy · last 30 days', 'With correction', 'Base model', 'data-accuracy-chart="days"',
        '+1 h', 'With correction', '+80 %', '0,2 · 1,0 °C', '100 %', '1,5 °C', '<td class="py-1.5">1</td>',
        'Rain chance · rained / dry', 'not listened', 'By hour of the day', 'data-accuracy-chart="hours"',
    ], false);

    expect($dashboard->html())
        ->toMatch('/aria-expanded="false"[^>]*aria-controls="forecast-accuracy"[^>]*aria-label="Expand Forecast accuracy"/')
        ->toContain('<div id="forecast-accuracy" class="hidden" x-bind:class="{ hidden: collapsed }">')
        ->toContain('data-accuracy-rows="'.e(json_encode([['24.9.2026', 1, 80.0, 0.2, 1.0, 100.0, 1.5, null, null, null, null, null, null]], JSON_THROW_ON_ERROR)).'"')
        // Stored without a base: no row for it.
        ->not->toContain('<td class="py-1.5 pr-6 whitespace-nowrap">Base model</td>')
        // The hour-of-day chart opens by its button, closed until then, and gets all 24 hours.
        ->toMatch('/aria-expanded="false"[^>]*aria-controls="forecast-accuracy-hours"/')
        ->toContain('<div id="forecast-accuracy-hours" class="hidden pt-2" x-bind:class="{ hidden: ! open }">')
        ->toContain('data-accuracy-rows="'.e(json_encode(byHour([11 => [100.0, 1, 0.2, 0.2, 0.2]]), JSON_THROW_ON_ERROR)).'"');
});

it('sets the base model beside the forecast shown', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5, base: [12.0, 12.4, 12.9])]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    Livewire::test(Dashboard::class)->assertSeeInOrder([
        'With correction</td>', '+80 %', '0,2 · 1,0 °C', '100 %', '1,5 °C',
        'Base model</td>', '+40 %', '0,6 · 1,0 °C', '0 %', '0,9 °C',
    ], false);
});

it('charts the chosen horizon, and the first one scored until it has come true', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    measuredAt($sensor, $issued + 7200, 1500);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5), scoredHorizon(2, 12.5, 13.0, 14.0)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);

    // Three hours ahead has not come true: the panel opens on one, and the control says so.
    $dashboard = Livewire::test(Dashboard::class)->assertSee('+80 %')->assertSet('accuracyHorizon', 1);

    // Every hour the forecast reaches is offered; the ones not scored yet are disabled.
    expect($dashboard->html())
        ->not->toMatch('/<[^>]*value="2"[^>]*disabled/')
        ->toMatch('/<[^>]*value="3"[^>]*disabled/')
        ->toMatch('/<[^>]*value="6"[^>]*disabled/');

    // The segmented control sends the value as a string.
    $dashboard->set('accuracyHorizon', '2')->assertSee('+33 %')->assertDontSee('+80 %');
    expect($dashboard->get('accuracyScore')['hours'])->toBe(2);

    // One that has not come true falls back, and the control follows.
    $dashboard->set('accuracyHorizon', 5)->assertSet('accuracyHorizon', 1);
});

it('moves the choice onto the horizon shown when a poll takes its scores away', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    measuredAt($sensor, $issued + 7200, 1500);
    $both = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5), scoredHorizon(2, 12.5, 13.0, 14.0)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);

    $dashboard = Livewire::test(Dashboard::class)->set('accuracyHorizon', 2)->assertSet('accuracyHorizon', 2);

    // The two-hour forecast is gone, and a newer forecast brings a fresh score.
    $both->update(['data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7800]);

    $dashboard->call('$refresh')->assertSet('accuracyHorizon', 1)->assertSee('+80 %');
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

    Livewire::test(Dashboard::class)->assertSeeInOrder(['Accuracy · last 30 days', '+1 h', '47 %', '/', 'no dry spell']);
});

it('shows no accuracy without a current forecast to fold it into', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    // Scored, but an hour older than the newest reading: the forecast block is gone.
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    Livewire::test(Dashboard::class)->assertDontSee('Forecast · next 6 hours')->assertDontSee('Accuracy · last 30 days');
});

it('keeps the score until the next forecast arrives', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0]['corrected']['count'])->toBe(1);

    // A reading alone completes the second forecast's hour, but the score waits for the next forecast.
    measuredAt($sensor, $issued + 7200, 1300);
    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0]['corrected']['count'])->toBe(1);

    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 7200]);
    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0]['corrected']['count'])->toBe(2);
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

it('scores afresh over a cached score of an older shape', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    measuredAt($sensor, $issued + 3600, 1300);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 3600]);

    // What an earlier deploy left under the same key, for the same forecast.
    Cache::put("forecast-accuracy:{$sensor->id}", ['shape' => 0, 'issuedAt' => $issued + 3600, 'scores' => [['inRange' => 100.0]]], 900);

    expect(Livewire::test(Dashboard::class)->get('forecastAccuracy')[0])->toHaveKey('corrected');
});
