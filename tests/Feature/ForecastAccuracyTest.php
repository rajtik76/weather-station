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

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-09-24 12:00:00', 'UTC'));
});

it('scores each horizon against the window that came n hours later', function (): void {
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

    expect(new ForecastAccuracy($sensor->id)->since($issued))->toEqual([
        ['hours' => 1, 'count' => 1, 'inRange' => 100.0, 'error' => 0.2, 'unchangedError' => 1.0, 'rainCount' => 0, 'rainCases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
        ['hours' => 2, 'count' => 1, 'inRange' => 0.0, 'error' => 2.0, 'unchangedError' => 3.0, 'rainCount' => 0, 'rainCases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
    ]);
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

    expect(new ForecastAccuracy($sensor->id)->since($issued)[0])
        ->toMatchArray(['rainCount' => 0, 'rainCases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null]);
});

it('reads a slot stamped twice by its first reading', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    measuredAt($sensor, $issued, 1200);
    // A drifting clock put two readings into the 09:00 slot; the service takes the first.
    measuredAt($sensor, $issued + 3600 + 2, 1300);
    measuredAt($sensor, $issued + 3600 + 598, 2000);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => [scoredHorizon(1, 12.0, 12.8, 13.5)]]);

    expect(new ForecastAccuracy($sensor->id)->since($issued)[0])->toMatchArray(['inRange' => 100.0, 'error' => 0.2]);
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

    expect(new ForecastAccuracy($sensor->id)->since($issued)[0])->toMatchArray(['hours' => 9, 'count' => 1, 'error' => 0.5]);
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

    expect(new ForecastAccuracy($sensor->id)->since($issued)[0]['count'])->toBe(1)
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
    $dashboard->assertSeeInOrder(['id="forecast"', 'Accuracy · last 7 days', '+1 h', '100 %', '0,2 °C', '1,0 °C', 'not listened'], false);

    expect($dashboard->html())
        ->toMatch('/aria-expanded="false"[^>]*aria-controls="forecast-accuracy"[^>]*aria-label="Expand Forecast accuracy"/')
        ->toContain('<div id="forecast-accuracy" class="hidden" x-bind:class="{ hidden: collapsed }">')
        // Every reading in range is not a success: the range aims at 80 %, so it was too wide.
        ->toMatch('/text-amber-600[^"]*">100 %/');
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
