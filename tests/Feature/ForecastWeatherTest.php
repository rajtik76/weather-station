<?php

declare(strict_types=1);

use App\Jobs\ForecastWeather;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\freezeTime;

beforeEach(function (): void {
    config()->set('forecast.url', 'http://forecast.test');
});

/**
 * What forecast/serve.py answers, trimmed to one horizon.
 *
 * @return array<string, mixed>
 */
function serviceForecast(int $issuedAt): array
{
    return [
        'issued_at' => $issuedAt,
        'model' => '2026-09-24T08:40:43.136429+00:00',
        'corrected' => true,
        'horizons' => [[
            'hours' => 1,
            'temperature' => ['low' => 12.4, 'mid' => 13.84, 'high' => 15.56],
            'humidity' => ['low' => 70.1, 'mid' => 75.7, 'high' => 80.2],
            'pressure' => ['low' => 976.0, 'mid' => 976.47, 'high' => 977.1],
            'rain_probability' => 0.023,
            'base' => [
                'temperature' => ['low' => 12.1, 'mid' => 13.46, 'high' => 15.9],
                'humidity' => ['low' => 69.0, 'mid' => 76.3, 'high' => 82.5],
            ],
        ]],
    ];
}

function forecastReading(Sensor $sensor, int $timestamp, int $temperature, int $humidity, int $pressure): void
{
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $timestamp,
        'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: $humidity, pressure: $pressure),
    ]);
}

it('sends the last sixty days of the sensor in service units and stores the forecast, its base included', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, now()->subDays(61)->getTimestamp(), 500, 5000, 98000);
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    forecastReading(Sensor::factory()->create(), $recent, 2000, 4000, 99000);
    Http::fake(['http://forecast.test/forecast' => Http::response(serviceForecast($recent))]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'longitude' => 13.40,
        'readings' => [['timestamp' => $recent, 'temperature' => 11.81, 'humidity' => 83.27, 'pressure' => 976.55]],
    ]);
    $forecast = Forecast::query()->sole();
    expect($forecast->sensor_id)->toBe($sensor->id)
        ->and($forecast->issued_at)->toBe($recent)
        ->and($forecast->model)->toBe('2026-09-24T08:40:43.136429+00:00')
        ->and($forecast->corrected)->toBeTrue()
        // toEqual: jsonb reorders keys and stores 976.0 as 976.
        ->and($forecast->data)->toEqual(serviceForecast($recent)['horizons']);
});

it('replaces the forecast issued from the same reading', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Forecast::factory()->for($sensor)->create(['issued_at' => $recent]);
    Http::fake(['http://forecast.test/forecast' => Http::response(serviceForecast($recent))]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(serviceForecast($recent)['horizons']);
});

it('asks nothing when the sensor has no recent readings', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    forecastReading($sensor, now()->subDays(61)->getTimestamp(), 1181, 8327, 97655);
    Http::fake(['http://forecast.test/forecast' => Http::response(serviceForecast(0))]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertNothingSent();
    assertDatabaseCount(Forecast::class, 0);
});

it('reports a failing service and stores nothing', function (): void {
    freezeTime();
    Exceptions::fake();
    $sensor = Sensor::factory()->create();
    forecastReading($sensor, now()->subMinutes(10)->getTimestamp(), 1181, 8327, 97655);
    Http::fake(['http://forecast.test/forecast' => Http::response(['error' => 'boom'], 500)]);

    dispatch_sync(new ForecastWeather($sensor));

    Exceptions::assertReported(RequestException::class);
    assertDatabaseCount(Forecast::class, 0);
});
