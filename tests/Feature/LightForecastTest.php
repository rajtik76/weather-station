<?php

declare(strict_types=1);

use App\Jobs\ForecastWeather;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Queries\CachedFit;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-10-03 12:00:00', 'UTC'));
    config()->set([
        'forecast.url' => 'http://forecast.test',
        'forecast.nwp.url' => '',
        'forecast.history_since' => null,
    ]);
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function lightShown(int $issuedAt): array
{
    $horizon = forecastHorizon(1, 13.0, 70.0, 0.0);
    $horizon['base'] = ['temperature' => ['low' => 11.0, 'mid' => 12.0, 'high' => 14.0], 'humidity' => $horizon['humidity']];

    return ['issued_at' => $issuedAt, 'model' => '2026-09-24', 'corrected' => true, 'correction' => 3, 'horizons' => [$horizon]];
}

/** @return array{model: string, version: string, fit: array{day: string, horizons: list<array{hours: int, baseline: float, trees: list<list<list<int|float|null>>>, widen: float}>, gate: array{envelope: list<float|null>, horizons: list<array{hours: int, factors: array<string, float>, widen: float}>}}} */
function lightFitted(): array
{
    return [
        'model' => '2026-09-24', 'version' => 'light-v6',
        'fit' => [
            'day' => '2026-10-03',
            'horizons' => [['hours' => 1, 'baseline' => 0.25, 'trees' => [[[6, null, 0, 1, 2], [0.5], [1.5]]], 'widen' => 0.1]],
            'gate' => ['envelope' => array_fill(0, 144, 2000.5), 'horizons' => [['hours' => 1, 'factors' => ['day:overcast' => 0.4], 'widen' => 0.2]]],
        ],
    ];
}

/** @return array<string, mixed> */
function lightIssued(int $issuedAt): array
{
    return [
        'issued_at' => $issuedAt, 'model' => '2026-09-24', 'version' => 'light-v6',
        'versions' => [
            'light-v5' => [['hours' => 1, 'temperature' => ['low' => 12.4, 'mid' => 13.5, 'high' => 14.6]]],
            'light-v6' => [['hours' => 1, 'temperature' => ['low' => 12.0, 'mid' => 12.8, 'high' => 14.0]]],
        ],
    ];
}

function lightReading(Sensor $sensor, int $timestamp): void
{
    Measurement::factory()->for($sensor)->v4()->create([
        'timestamp' => $timestamp,
        'data' => (string) litWindow(12345, 12000, 13000),
        'created_at' => now(),
    ]);
}

it('stores the sensor experiment beside unchanged shown and base bands', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    lightReading(Sensor::factory()->create(), $issued);
    $shown = lightShown($issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['model' => $shown['model'], 'correction' => 3, 'targets' => []]),
        'http://forecast.test/forecast' => Http::response($shown),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));
    dispatch_sync(new ForecastWeather($sensor));

    $stored = Forecast::query()->sole();
    $versions = lightIssued($issued)['versions'];
    expect($stored->data)->toEqual([[
        ...$shown['horizons'][0],
        'candidates' => [
            'correction' => $shown['horizons'][0]['temperature'],
            'light-v5' => $versions['light-v5'][0]['temperature'],
            'light-v6' => $versions['light-v6'][0]['temperature'],
        ],
        'experiment' => ['version' => 'light-v6', 'temperature' => $versions['light-v6'][0]['temperature']],
        'shown_by' => 'correction',
    ]]);
    expect($stored->model)->toBe($shown['model']);
    $request = Http::recorded(fn (Request $request): bool => $request->url() === 'http://forecast.test/light-forecast')->sole()[0];
    expect($request['readings'])->toEqual([[
        'timestamp' => $issued, 'temperature' => 12.0, 'humidity' => 60.0, 'pressure' => 970.0,
        'illuminance' => 123.45, 'received_at' => $issued, 'temperature_min' => 11.5, 'temperature_max' => 12.5,
    ]]);
    Http::assertSentCount(4);
});

it('shows the band of the model leading the race for the target\'s part of the day and names it', function (): void {
    $sensor = Sensor::factory()->create();
    racedReading($sensor, '2026-10-02 12:00:00', 1280);
    racedForecast($sensor, '2026-10-02 11:00:00', 1, ['correction' => 13.0, 'light-v6' => 12.8, 'light-v5' => 13.5, 'base' => 12.0]);
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    $shown = lightShown($issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response($shown),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    $horizon = Forecast::query()->where('issued_at', $issued)->sole()->data[0];
    expect($horizon['shown_by'] ?? null)->toBe('light-v6')
        ->and($horizon['temperature'])->toEqual(lightIssued($issued)['versions']['light-v6'][0]['temperature'])
        ->and($horizon['candidates']['correction'] ?? null)->toEqual($shown['horizons'][0]['temperature']);
});

it('fits the experiment from the local midnight of FORECAST_HISTORY_SINCE and sends the fit back unchanged', function (): void {
    config()->set('forecast.history_since', '2026-09-17');
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/light-correction'
        && $request['since'] === Date::parse('2026-09-16 22:00:00', 'UTC')->getTimestamp()
        && $request['readings'][0]['temperature_min'] === 11.5);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/light-forecast'
        && $request['experiment'] === lightFitted());
});

it('preserves the main forecast when an experimental request fails', function (string $endpoint): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Exceptions::fake();
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
        "http://forecast.test/{$endpoint}" => Http::response([], 500),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(shownByCorrection(lightShown($issued)['horizons']));
    Exceptions::assertReported(RequestException::class);
})->with(['fit failure' => 'light-correction', 'forecast failure' => 'light-forecast']);

it('refits a stale experiment once without refitting the main correction', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::sequence()->push(lightFitted())->push(lightFitted()),
        'http://forecast.test/light-forecast' => Http::sequence()->pushStatus(409)->push(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data[0]['experiment']['version'] ?? null)->toBe('light-v6');
    Http::assertSentCount(6);
});

it('stores the main forecast without an experiment when the experiment answers no horizon', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    $fitted = lightFitted();
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::response($fitted),
        'http://forecast.test/light-forecast' => Http::response([...lightIssued($issued), 'versions' => ['light-v5' => [], 'light-v6' => []]]),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(shownByCorrection(lightShown($issued)['horizons']));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/light-forecast'
        && $request['experiment'] === $fitted);
});

it('preserves the main forecast when the experiment connection is refused', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Exceptions::fake();
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::failedConnection(),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(shownByCorrection(lightShown($issued)['horizons']));
    Exceptions::assertReported(ConnectionException::class);
});

it('rejects an experiment from a different issue or model', function (array $different): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Exceptions::fake();
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response([...lightIssued($issued), ...$different]),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(shownByCorrection(lightShown($issued)['horizons']));
    Exceptions::assertReported(UnexpectedValueException::class);
})->with(['another issue' => [['issued_at' => 1]], 'another model' => [['model' => 'old']]]);

it('keeps the forecast and its race pick when the service answers in the shape before light-v6', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Exceptions::fake();
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response([
            'issued_at' => $issued, 'model' => '2026-09-24', 'version' => 'light-v5',
            'horizons' => [['hours' => 1, 'temperature' => ['low' => 12.4, 'mid' => 13.5, 'high' => 14.6]]],
        ]),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(shownByCorrection(lightShown($issued)['horizons']));
    Exceptions::assertReported(UnexpectedValueException::class);
});

it('skips experimental requests for an old upload', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp() - 1800;
    lightReading($sensor, $issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data)->toEqual(shownByCorrection(lightShown($issued)['horizons']));
    Http::assertSentCount(2);
});

it('issues the experiment from a window without light', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    $data = litWindow(12345, 12000, 13000)->jsonSerialize();
    unset($data['illuminance'], $data['illuminance_min'], $data['illuminance_max']);
    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => $issued, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data[0]['experiment']['version'] ?? null)->toBe('light-v6');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://forecast.test/light-forecast'
        && $request['readings'][0]['illuminance'] === null);
});

it('caches the calibration per sensor, local day of the newest reading and history start', function (): void {
    $sensor = Sensor::factory()->create();
    $other = Sensor::factory()->create();
    $calls = 0;
    $fit = function () use (&$calls): array {
        $calls++;

        return lightFitted();
    };
    CachedFit::lightCorrection($sensor->id, '2026-10-03', null, $fit)->current();
    CachedFit::lightCorrection($sensor->id, '2026-10-03', null, $fit)->current();
    expect($calls)->toBe(1);
    CachedFit::lightCorrection($other->id, '2026-10-03', null, $fit)->current();
    expect($calls)->toBe(2);

    CachedFit::lightCorrection($sensor->id, '2026-10-04', null, $fit)->current();
    expect($calls)->toBe(3);

    CachedFit::lightCorrection($sensor->id, '2026-10-04', 1789596000, $fit)->current();

    expect($calls)->toBe(4);
});

it('refits when the newest reading reaches a new local day, not when the clock does', function (): void {
    $sensor = Sensor::factory()->create();
    $slots = array_map(fn (string $slot): int => Date::parse($slot, 'UTC')->getTimestamp(), ['2026-10-03 21:40', '2026-10-03 21:50', '2026-10-03 22:00']);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::sequence(array_map(lightShown(...), $slots)),
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::sequence(array_map(lightIssued(...), $slots)),
    ]);

    foreach ($slots as $slot) {
        $this->travelTo(Date::createFromTimestamp($slot + 630));
        lightReading($sensor, $slot + 570);
        dispatch_sync(new ForecastWeather($sensor));
    }

    $fits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/light-correction'))
        ->map(fn (array $pair): int => (int) max([0, ...array_column($pair[0]['readings'], 'timestamp')]))
        ->values()
        ->all();
    expect($fits)->toBe([$slots[0] + 570, $slots[2] + 570]);
});

it('fits through the newest reading of the window it issues from, not one that arrived since', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => function () use ($sensor, $issued): PromiseInterface {
            lightReading($sensor, $issued + 600);

            return Http::response(lightShown($issued));
        },
        'http://forecast.test/light-correction' => Http::response(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    $fit = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/light-correction'))->sole()[0];
    expect(array_column($fit['readings'], 'timestamp'))->toBe([$issued]);
});

it('stores the main forecast before the experiment is fitted', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::response(lightShown($issued)),
        'http://forecast.test/light-correction' => fn (): PromiseInterface => Forecast::query()->where('sensor_id', $sensor->id)->exists()
            ? Http::response(lightFitted())
            : Http::response([], 500),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->sole()->data[0]['experiment']['version'] ?? null)->toBe('light-v6');
});

it('pauses the experiment for an hour after it fails', function (): void {
    $sensor = Sensor::factory()->create();
    $issued = now()->getTimestamp();
    lightReading($sensor, $issued);
    Exceptions::fake();
    Http::fake([
        'http://forecast.test/correction' => Http::response(['targets' => []]),
        'http://forecast.test/forecast' => Http::sequence()
            ->push(lightShown($issued))
            ->push(lightShown($issued + 600))
            ->push(lightShown($issued + 3600)),
        'http://forecast.test/light-correction' => Http::sequence()->pushStatus(504)->push(lightFitted()),
        'http://forecast.test/light-forecast' => Http::response(lightIssued($issued + 3600)),
    ]);

    dispatch_sync(new ForecastWeather($sensor));
    $this->travel(10)->minutes();
    lightReading($sensor, $issued + 600);
    dispatch_sync(new ForecastWeather($sensor));

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/light-'))->count())->toBe(1);

    $this->travelTo(Date::createFromTimestamp($issued + 3600));
    lightReading($sensor, $issued + 3600);
    dispatch_sync(new ForecastWeather($sensor));

    expect(Forecast::query()->where('issued_at', $issued + 3600)->sole()->data[0]['experiment']['version'] ?? null)->toBe('light-v6');
});
