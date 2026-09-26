<?php

declare(strict_types=1);

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

const BACKFILL_MODEL = '2026-09-24T08:40:43.136429+00:00';

beforeEach(function (): void {
    config()->set('forecast.url', 'http://forecast.test');
});

/**
 * The service running BACKFILL_MODEL, answering /base with the given answer.
 *
 * @param  array<string, mixed>|null  $answer
 */
function fakeService(?array $answer = null): void
{
    Http::fake([
        'http://forecast.test/health' => Http::response(['status' => 'ok', 'model' => BACKFILL_MODEL]),
        'http://forecast.test/base' => Http::response($answer ?? baseAnswer([])),
    ]);
}

/** How many times /base was asked. */
function baseRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/base'))->count();
}

/**
 * @return array{low: float, mid: float, high: float}
 */
function backfillBand(float $mid): array
{
    return ['low' => $mid - 1, 'mid' => $mid, 'high' => $mid + 1];
}

/**
 * A stored horizon, with a base when given its temperature middle.
 *
 * @return array<string, mixed>
 */
function storedHorizon(int $hours, ?float $base = null): array
{
    return [
        'hours' => $hours,
        'temperature' => backfillBand(13.0),
        'humidity' => backfillBand(75.0),
        'pressure' => backfillBand(976.5),
        'rain_probability' => 0.02,
        ...($base === null ? [] : ['base' => ['temperature' => backfillBand($base), 'humidity' => backfillBand(74.0)]]),
    ];
}

/**
 * What /base answers for the given windows, the temperature middle 10 + hours.
 *
 * @param  list<int>  $issuedAt
 * @return array<string, mixed>
 */
function baseAnswer(array $issuedAt, string $model = BACKFILL_MODEL): array
{
    return [
        'model' => $model,
        'forecasts' => array_map(fn (int $at): array => [
            'issued_at' => $at,
            'horizons' => array_map(fn (int $hours): array => [
                'hours' => $hours,
                'temperature' => backfillBand(10.0 + $hours),
                'humidity' => backfillBand(70.0 + $hours),
            ], [1, 2]),
        ], $issuedAt),
    ];
}

function backfillReading(Sensor $sensor, int $timestamp): void
{
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV1(temperature: 1181, humidity: 8327, pressure: 97655),
    ]);
}

it('fills in the base of stored forecasts from readings reaching three days before them', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    backfillReading($sensor, $issued - 3 * 86_400 - 600);
    backfillReading($sensor, $issued - 3 * 86_400);
    backfillReading($sensor, $issued + 600 + 5);
    backfillReading($sensor, $issued + 1200);
    $first = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1), storedHorizon(2)]]);
    $second = Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 600, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1), storedHorizon(2)]]);
    fakeService(baseAnswer([$issued, $issued + 600]));

    expect(Artisan::call('forecast:backfill-base'))->toBe(0)
        ->and(Artisan::output())->toContain("{$sensor->name}: 2 forecasts given their base");

    // From three days before the first through the last one's own window, not the one after it.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/base')
        && $request['since'] === $issued
        && array_column($request['readings'], 'timestamp') === [$issued - 3 * 86_400, $issued + 605]);

    // The forecast shown stays as it was; only the base is added.
    expect($first->refresh()->data[1])->toEqual([...storedHorizon(2), 'base' => ['temperature' => backfillBand(12.0), 'humidity' => backfillBand(72.0)]])
        ->and(data_get($second->refresh()->data, '0.base.temperature'))->toEqual(backfillBand(11.0));
});

it('does not ask for a forecast that has its base, one made by a model the service no longer runs, or one with no horizons', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    backfillReading($sensor, $issued);
    $kept = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1, base: 12.5)]]);
    $older = Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 600, 'model' => '2026-09-01T00:00:00+00:00', 'data' => [storedHorizon(1)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 1200, 'model' => BACKFILL_MODEL, 'data' => []]);
    fakeService(baseAnswer([$issued, $issued + 600, $issued + 1200]));

    Artisan::call('forecast:backfill-base');
    expect(Artisan::output())->toContain("{$sensor->name}: 0 forecasts given their base");

    // Asking again on every run would fill nothing.
    expect(baseRequests())->toBe(0)
        ->and(data_get($kept->refresh()->data, '0.base.temperature'))->toEqual(backfillBand(12.5))
        ->and($older->refresh()->data[0])->not->toHaveKey('base');
});

it('leaves a forecast whose horizons the answer does not all have, and fills the rest', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-24 08:00:00', 'UTC')->getTimestamp();
    backfillReading($sensor, $issued);
    // The answer carries +1 and +2 h only.
    $longer = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1), storedHorizon(3)]]);
    $filled = Forecast::factory()->for($sensor)->create(['issued_at' => $issued + 600, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1)]]);
    fakeService(baseAnswer([$issued, $issued + 600]));

    Artisan::call('forecast:backfill-base');

    expect(Artisan::output())->toContain("{$sensor->name}: 1 forecasts given their base")
        ->and($longer->refresh()->data[0])->not->toHaveKey('base')
        ->and(data_get($filled->refresh()->data, '0.base.temperature'))->toEqual(backfillBand(11.0));
});

it('asks a week of forecasts at a time', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = Date::parse('2026-09-10 08:00:00', 'UTC')->getTimestamp();
    $weekOn = $issued + 7 * 86_400;
    backfillReading($sensor, $issued);
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1)]]);
    Forecast::factory()->for($sensor)->create(['issued_at' => $weekOn, 'model' => BACKFILL_MODEL, 'data' => [storedHorizon(1)]]);
    Http::fake([
        'http://forecast.test/health' => Http::response(['status' => 'ok', 'model' => BACKFILL_MODEL]),
        'http://forecast.test/base' => Http::sequence()
            ->push(baseAnswer([$issued]))
            ->push(baseAnswer([$weekOn])),
    ]);

    Artisan::call('forecast:backfill-base');
    expect(Artisan::output())->toContain("{$sensor->name}: 2 forecasts given their base");

    expect(baseRequests())->toBe(2);
});
