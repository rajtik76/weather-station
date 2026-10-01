<?php

declare(strict_types=1);

use App\Jobs\ForecastWeather;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\StationSite;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\freezeTime;

beforeEach(function (): void {
    config()->set('forecast.url', 'http://forecast.test');
});

/**
 * @return array<string, mixed>
 */
function serviceForecast(int $issuedAt): array
{
    return [
        'issued_at' => $issuedAt,
        'model' => '2026-09-24T08:40:43.136429+00:00',
        'corrected' => true,
        'correction' => 2,
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

/**
 * @return array<string, mixed>
 */
function fittedCorrection(string $model = '2026-09-24T08:40:43.136429+00:00'): array
{
    return [
        'model' => $model,
        'correction' => 3,
        'targets' => ['T_1h' => ['intercept' => 0.4, 'coefficients' => ['error_1h' => 0.25], 'widen' => 0.1]],
    ];
}

it('fits the correction on the last sixty days and forecasts from the last 56 hours with it, base included', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    $twoDaysAgo = now()->subHours(55)->getTimestamp();
    forecastReading($sensor, now()->subDays(61)->getTimestamp(), 500, 5000, 98000);
    forecastReading($sensor, now()->subDays(59)->getTimestamp(), 512, 5034, 98012);
    forecastReading($sensor, $twoDaysAgo, 1050, 8012, 97634);
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    forecastReading(Sensor::factory()->create(), $recent, 2000, 4000, 99000);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/correction'
        && $request->data() === [
            'longitude' => StationSite::LONGITUDE,
            'readings' => [
                ['timestamp' => now()->subDays(59)->getTimestamp(), 'temperature' => 5.12, 'humidity' => 50.34, 'pressure' => 980.12],
                ['timestamp' => $twoDaysAgo, 'temperature' => 10.5, 'humidity' => 80.12, 'pressure' => 976.34],
                ['timestamp' => $recent, 'temperature' => 11.81, 'humidity' => 83.27, 'pressure' => 976.55],
            ],
        ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/forecast'
        && $request->data() === [
            'longitude' => StationSite::LONGITUDE,
            'readings' => [
                ['timestamp' => $twoDaysAgo, 'temperature' => 10.5, 'humidity' => 80.12, 'pressure' => 976.34],
                ['timestamp' => $recent, 'temperature' => 11.81, 'humidity' => 83.27, 'pressure' => 976.55],
            ],
            'correction' => fittedCorrection(),
        ]);
    $forecast = Forecast::query()->sole();
    expect($forecast->sensor_id)->toBe($sensor->id)
        ->and($forecast->issued_at)->toBe($recent)
        ->and($forecast->model)->toBe('2026-09-24T08:40:43.136429+00:00')
        ->and($forecast->corrected)->toBeTrue()
        ->and($forecast->correction)->toBe(2)
        // toEqual: jsonb reorders keys and stores 976.0 as 976.
        ->and($forecast->data)->toEqual(serviceForecast($recent)['horizons']);
});

it('fits the correction from the local midnight of FORECAST_HISTORY_SINCE', function (): void {
    $this->travelTo(Date::parse('2026-09-26 12:00:00', 'UTC'));
    config()->set('forecast.history_since', '2026-09-17');
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/correction'
        && $request['since'] === Date::parse('2026-09-16 22:00:00', 'UTC')->getTimestamp());
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/forecast' && ! isset($request['since']));
});

it('sends a reading stamped a little ahead of the server but not one stamped hours ahead', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    $ahead = now()->addMinutes(5)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    forecastReading($sensor, $ahead, 1181, 8327, 97655);
    forecastReading($sensor, now()->addHours(2)->getTimestamp(), 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($ahead)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/correction'
        && array_column($request['readings'], 'timestamp') === [$recent, $ahead]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/forecast'
        && array_column($request['readings'], 'timestamp') === [$recent, $ahead]);
});

it('reports a FORECAST_HISTORY_SINCE that is not a date and fits the correction without it', function (string $since): void {
    freezeTime();
    Exceptions::fake();
    config()->set('forecast.history_since', $since);
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Exceptions::assertReported(InvalidArgumentException::class);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/correction' && ! isset($request['since']));
    expect(Forecast::query()->count())->toBe(1);
})->with([
    'day and month swapped' => '2026-17-09',
    'not a date at all' => 'shield',
]);

it('issues one forecast an hour, from the first reading of the hour', function (string $issuedBefore, int $expected): void {
    $this->travelTo(Date::parse('2026-10-01 10:25:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    $recent = Date::parse('2026-10-01 10:20:00', 'UTC')->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Forecast::factory()->for($sensor)->create(['issued_at' => Date::parse($issuedBefore, 'UTC')->getTimestamp()]);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->count())->toBe($expected);
})->with([
    'already issued this hour' => ['2026-10-01 10:00:00', 1],
    'last issued the hour before' => ['2026-10-01 09:50:00', 2],
]);

it('fits the correction once a day', function (): void {
    $sensor = Sensor::factory()->create();
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => fn (Request $request) => Http::response(serviceForecast(intdiv(now()->getTimestamp(), 600) * 600)),
    ]);

    foreach (['2026-10-01 10:05:00', '2026-10-01 11:05:00', '2026-10-02 10:05:00'] as $at) {
        $this->travelTo(Date::parse($at, 'UTC'));
        forecastReading($sensor, now()->getTimestamp(), 1181, 8327, 97655);
        dispatch_sync(new ForecastWeather($sensor));
    }

    Http::assertSentCount(5);
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === 'http://forecast.test/correction'))->toHaveCount(2)
        ->and(Forecast::query()->count())->toBe(3);
});

it('forecasts without a correction while the history is too short to fit one', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response([...fittedCorrection(), 'targets' => (object) []]),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/forecast' && ! isset($request['correction']));
    expect(Forecast::query()->count())->toBe(1);
});

it('refits the correction at once when FORECAST_HISTORY_SINCE changes', function (): void {
    $sensor = Sensor::factory()->create();
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => fn (Request $request) => Http::response(serviceForecast(intdiv(now()->getTimestamp(), 600) * 600)),
    ]);

    foreach (['2026-10-01 10:05:00' => '2026-09-17', '2026-10-01 11:05:00' => '2026-09-30'] as $at => $since) {
        $this->travelTo(Date::parse($at, 'UTC'));
        config()->set('forecast.history_since', $since);
        forecastReading($sensor, now()->getTimestamp(), 1181, 8327, 97655);
        dispatch_sync(new ForecastWeather($sensor));
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/correction'
        && $request['since'] === Date::parse('2026-09-29 22:00:00', 'UTC')->getTimestamp());
});

it('refits a correction the service calls stale and forecasts with the new one', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::sequence()
            ->push(fittedCorrection('2026-01-01T00:00:00+00:00'))
            ->push(fittedCorrection()),
        'http://forecast.test/forecast' => fn (Request $request) => $request['correction']['model'] === '2026-01-01T00:00:00+00:00'
            ? Http::response(['error' => 'correction was fitted for another model or correction version'], 409)
            : Http::response(serviceForecast($recent)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->issued_at)->toBe($recent);
});

it('asks nothing when the sensor has no reading in the last 56 hours', function (): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    forecastReading($sensor, now()->subHours(57)->getTimestamp(), 1181, 8327, 97655);
    Http::fake();

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertNothingSent();
    assertDatabaseCount(Forecast::class, 0);
});

it('reports a failing service and stores nothing', function (string $failing): void {
    freezeTime();
    Exceptions::fake();
    $sensor = Sensor::factory()->create();
    forecastReading($sensor, now()->subMinutes(10)->getTimestamp(), 1181, 8327, 97655);
    Http::fake([
        "http://forecast.test/{$failing}" => Http::response(['error' => 'boom'], 500),
        'http://forecast.test/*' => Http::response(fittedCorrection()),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Exceptions::assertReported(RequestException::class);
    assertDatabaseCount(Forecast::class, 0);
})->with(['correction', 'forecast']);

/**
 * @return array{hourly: array{time: list<int>, temperature_2m: list<float>}}
 */
function nwpAnswer(float $temperature): array
{
    $hour = intdiv(now()->getTimestamp(), 3600) * 3600;

    return ['hourly' => [
        'time' => [$hour, $hour + 3600, $hour + 7200, $hour + 10800],
        'temperature_2m' => [$temperature, $temperature, $temperature, $temperature],
    ]];
}

it('stores the weather model\'s temperature beside each horizon', function (): void {
    freezeTime();
    config()->set('forecast.nwp.url', 'https://nwp.test/v1/forecast');
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
        'https://nwp.test/*' => Http::response(nwpAnswer(14.2)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data[0])->toHaveKey('nwp', ['temperature' => 14.2]);
});

it('stores the forecast without the weather model when the model fails', function (): void {
    freezeTime();
    Exceptions::fake();
    config()->set('forecast.nwp.url', 'https://nwp.test/v1/forecast');
    $sensor = Sensor::factory()->create();
    $recent = now()->subMinutes(10)->getTimestamp();
    forecastReading($sensor, $recent, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($recent)),
        'https://nwp.test/*' => Http::response(['reason' => 'boom'], 500),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Exceptions::assertReported(RequestException::class);
    expect(Forecast::query()->sole()->data)->toEqual(serviceForecast($recent)['horizons']);
});

it('asks the weather model nothing for a forecast issued from a late batch', function (): void {
    freezeTime();
    config()->set('forecast.nwp.url', 'https://nwp.test/v1/forecast');
    $sensor = Sensor::factory()->create();
    $late = now()->subMinutes(40)->getTimestamp();
    forecastReading($sensor, $late, 1181, 8327, 97655);
    Http::fake([
        'http://forecast.test/correction' => Http::response(fittedCorrection()),
        'http://forecast.test/forecast' => Http::response(serviceForecast($late)),
        'https://nwp.test/*' => Http::response(nwpAnswer(14.2)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://nwp.test'));
    expect(Forecast::query()->sole()->data[0])->not->toHaveKey('nwp');
});
