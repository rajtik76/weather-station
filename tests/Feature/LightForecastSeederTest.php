<?php

declare(strict_types=1);

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Queries\CachedForecastAccuracy;
use Database\Seeders\LightForecastSeeder;
use Illuminate\Support\Facades\Date;

use function Pest\Laravel\seed;

/** @return list<array<string, mixed>> */
function demoHorizons(): array
{
    return array_map(fn (int $hours): array => [
        ...forecastHorizon($hours, 13.0, 70.0, 0.0),
        'temperature' => ['low' => 12.0, 'mid' => 13.0, 'high' => 14.0],
        'base' => [
            'temperature' => ['low' => 10.0, 'mid' => 11.0, 'high' => 12.0],
            'humidity' => ['low' => 65.0, 'mid' => 70.0, 'high' => 75.0],
        ],
    ], range(1, 6));
}

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-10-03 12:00:00', 'UTC'));
});

it('adds a labelled synthetic curve to all horizons without changing shown or base forecasts', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->subHours(7)->getTimestamp();
    Measurement::factory()->for($sensor)->v4()->create([
        'timestamp' => $issued + 37,
        'data' => (string) litWindow(100000, 100000, 100000),
    ]);
    $forecast = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => demoHorizons()]);

    seed(LightForecastSeeder::class);

    $forecast->refresh();
    foreach ($forecast->data as $position => $horizon) {
        expect($horizon['experiment'] ?? null)->toEqual([
            'version' => 'demo-light-v1', 'temperature' => ['low' => 11.0, 'mid' => 12.0, 'high' => 13.0], 'synthetic' => true,
        ]);
        unset($horizon['experiment']);
        expect($horizon)->toEqual(demoHorizons()[$position]);
    }
    $first = $forecast->data;

    seed(LightForecastSeeder::class);

    expect($forecast->refresh()->data)->toEqual($first);
});

it('does not replace a recorded experiment or use another sensors light', function (): void {
    $sensor = Sensor::factory()->create();
    $other = Sensor::factory()->create();
    $issued = now()->subHours(7)->getTimestamp();
    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => $issued, 'data' => (string) litWindow(100000, 100000, 100000)]);
    $data = demoHorizons();
    $data[0]['experiment'] = ['version' => 'light-v1', 'temperature' => ['low' => 12.0, 'mid' => 12.5, 'high' => 13.0]];
    $real = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => $data]);
    $unlit = Forecast::factory()->for($other)->create(['issued_at' => $issued, 'data' => demoHorizons()]);

    seed(LightForecastSeeder::class);

    expect($real->refresh()->data[0]['experiment'] ?? null)->toEqual($data[0]['experiment']);
    expect($unlit->refresh()->data)->toEqual(demoHorizons());
});

it('refreshes the graph cache and distinguishes the synthetic preview from measured prototype performance', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->subHours(7)->getTimestamp();
    foreach ([$issued => 1200, $issued + 6 * 3600 => 1300] as $timestamp => $temperature) {
        $data = litWindow(100000, 100000, 100000)->jsonSerialize();
        $data['temperature'] = $temperature;
        Measurement::factory()->for($sensor)->v4()->create(['timestamp' => $timestamp, 'data' => json_encode($data, JSON_THROW_ON_ERROR)]);
    }
    Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => demoHorizons()]);
    $cached = new CachedForecastAccuracy($sensor->id);
    expect($cached->lastDays(30)[0])->not->toHaveKey('experiment');

    seed(LightForecastSeeder::class);

    expect($cached->lastDays(30)[0]['experiment'] ?? null)->toBe(['version' => 'demo-light-v1', 'synthetic' => true]);
    $this->get(route('forecast'))
        ->assertSee('Synthetic preview: VEML prototype (demo-light-v1)')
        ->assertSee('not measured prototype performance');
});

it('does not seed synthetic forecasts in production', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => $issued, 'data' => (string) litWindow(100000, 100000, 100000)]);
    $forecast = Forecast::factory()->for($sensor)->create(['issued_at' => $issued, 'data' => demoHorizons()]);
    app()->instance('env', 'production');

    new LightForecastSeeder()->run();

    expect($forecast->refresh()->data)->toEqual(demoHorizons());
});
