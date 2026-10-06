<?php

declare(strict_types=1);

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

const REPLAY_MODEL = '2026-09-24T08:40:43.136429+00:00';

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-10-03 12:00:00', 'UTC'));
    config()->set(['forecast.url' => 'http://forecast.test', 'forecast.history_since' => null]);
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $overrides  by URL, in place of the default answer
 */
function fakeReplayService(array $overrides = []): void
{
    Http::fake([
        'http://forecast.test/health' => Http::response(['status' => 'ok', 'model' => REPLAY_MODEL, 'correction' => 3, 'experiment' => 'light-v2']),
        'http://forecast.test/light-correction' => Http::response([
            'model' => REPLAY_MODEL, 'version' => 'light-v2',
            'profile' => ['day' => '2026-10-02', 'envelope' => array_fill(0, 182, 2000.5)],
        ]),
        'http://forecast.test/light-forecast' => fn (Request $request): PromiseInterface => Http::response([
            'issued_at' => intdiv(max([0, ...array_column($request['readings'], 'timestamp')]), 600) * 600,
            'model' => REPLAY_MODEL, 'version' => 'light-v2',
            'horizons' => [['hours' => 1, 'temperature' => ['low' => 12.0, 'mid' => 12.8, 'high' => 14.0]]],
        ]),
        ...$overrides,
    ]);
}

function arrivedReading(Sensor $sensor, string $stamped, string $arrived): void
{
    Measurement::factory()->for($sensor)->v4()->create([
        'timestamp' => Date::parse($stamped, 'UTC')->getTimestamp(),
        'data' => (string) litWindow(12345, 12000, 13000),
        'created_at' => Date::parse($arrived, 'UTC'),
    ]);
}

function replayedForecast(Sensor $sensor, string $issued, string $model = REPLAY_MODEL): Forecast
{
    return Forecast::factory()->for($sensor)->create([
        'issued_at' => Date::parse($issued, 'UTC')->getTimestamp(),
        'model' => $model,
        'data' => [forecastHorizon(1, 13.0, 70.0, 0.0)],
    ]);
}

/** @return list<list<int>> */
function sentStamps(string $endpoint): array
{
    return array_values(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), $endpoint))
        ->map(fn (array $pair): array => array_map(intval(...), array_column($pair[0]['readings'], 'timestamp')))
        ->all());
}

it('replays the experiment from the readings the server held when each forecast and the day\'s fit were made', function (): void {
    fakeReplayService();
    $sensor = Sensor::factory()->create();
    arrivedReading($sensor, '2026-10-01 21:00:00', '2026-10-01 21:01:00');
    arrivedReading($sensor, '2026-10-01 21:30:00', '2026-10-02 06:00:00');
    arrivedReading($sensor, '2026-10-01 22:05:00', '2026-10-01 22:06:00');
    arrivedReading($sensor, '2026-10-02 07:50:00', '2026-10-02 08:30:00');
    arrivedReading($sensor, '2026-10-02 08:01:00', '2026-10-02 08:02:00');
    arrivedReading($sensor, '2026-10-02 09:01:00', '2026-10-02 09:02:00');
    $before = replayedForecast($sensor, '2026-10-01 21:00:00');
    $midnight = replayedForecast($sensor, '2026-10-01 22:00:00');
    $morning = replayedForecast($sensor, '2026-10-02 08:00:00');
    $otherModel = replayedForecast($sensor, '2026-10-02 09:00:00', 'old');

    expect(Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']))->toBe(0);
    expect(Artisan::output())->toContain('2 forecasts given the light experiment');

    $stamp = fn (string $time): int => Date::parse($time, 'UTC')->getTimestamp();
    expect(sentStamps('/light-correction'))->toBe([[$stamp('2026-10-01 21:00:00'), $stamp('2026-10-01 22:05:00')]]);
    expect(sentStamps('/light-forecast')[1])->toBe([
        $stamp('2026-10-01 21:00:00'), $stamp('2026-10-01 21:30:00'), $stamp('2026-10-01 22:05:00'), $stamp('2026-10-02 08:01:00'),
    ]);
    foreach ([$midnight, $morning] as $forecast) {
        expect($forecast->refresh()->data[0]['experiment'] ?? null)->toEqual([
            'version' => 'light-v2', 'temperature' => ['low' => 12.0, 'mid' => 12.8, 'high' => 14.0],
        ]);
    }
    expect($before->refresh()->data[0])->not->toHaveKey('experiment');
    expect($otherModel->refresh()->data[0])->not->toHaveKey('experiment');

    Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']);
    expect(Artisan::output())->toContain('0 forecasts');
    expect(sentStamps('/light-correction'))->toHaveCount(1);
    expect(sentStamps('/light-forecast'))->toHaveCount(2);
});

it('skips a forecast whose upload came too late for the experiment', function (): void {
    fakeReplayService();
    $sensor = Sensor::factory()->create();
    arrivedReading($sensor, '2026-10-02 08:01:00', '2026-10-02 08:40:00');
    $late = replayedForecast($sensor, '2026-10-02 08:00:00');

    expect(Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']))->toBe(0);

    expect($late->refresh()->data[0])->not->toHaveKey('experiment');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/light-'));
});

it('refuses a date that is not Y-m-d', function (): void {
    expect(Artisan::call('forecast:backfill-light', ['from' => '2026-17-09']))->toBe(1);

    Http::assertNothingSent();
});

it('reports and skips an answer issued for another window', function (): void {
    fakeReplayService();
    $sensor = Sensor::factory()->create();
    arrivedReading($sensor, '2026-10-02 07:01:00', '2026-10-02 07:02:00');
    arrivedReading($sensor, '2026-10-02 07:20:00', '2026-10-02 07:02:00');
    arrivedReading($sensor, '2026-10-02 09:01:00', '2026-10-02 09:02:00');
    $ahead = replayedForecast($sensor, '2026-10-02 07:00:00');
    $next = replayedForecast($sensor, '2026-10-02 09:00:00');
    Exceptions::fake();

    Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']);

    Exceptions::assertReported(UnexpectedValueException::class);
    expect($ahead->refresh()->data[0])->not->toHaveKey('experiment');
    expect($next->refresh()->data[0])->toHaveKey('experiment');
});

it('leaves a forecast that has the current experiment version on any horizon', function (): void {
    fakeReplayService();
    $sensor = Sensor::factory()->create();
    arrivedReading($sensor, '2026-10-02 08:01:00', '2026-10-02 08:02:00');
    $data = [forecastHorizon(1, 13.0, 70.0, 0.0), [
        ...forecastHorizon(2, 13.0, 70.0, 0.0),
        'experiment' => ['version' => 'light-v2', 'temperature' => ['low' => 11.0, 'mid' => 12.0, 'high' => 13.0]],
    ]];
    $live = Forecast::factory()->for($sensor)->create(['issued_at' => Date::parse('2026-10-02 08:00:00', 'UTC')->getTimestamp(), 'model' => REPLAY_MODEL, 'data' => $data]);

    Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']);

    expect($live->refresh()->data)->toEqual($data);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/light-'));
});

it('replaces an older experiment version and drops it from horizons the current one does not answer', function (): void {
    fakeReplayService();
    $sensor = Sensor::factory()->create();
    arrivedReading($sensor, '2026-10-02 08:01:00', '2026-10-02 08:02:00');
    $olderExperiment = ['version' => 'light-v1', 'temperature' => ['low' => 11.0, 'mid' => 12.0, 'high' => 13.0]];
    $older = Forecast::factory()->for($sensor)->create([
        'issued_at' => Date::parse('2026-10-02 08:00:00', 'UTC')->getTimestamp(),
        'model' => REPLAY_MODEL,
        'data' => [
            [...forecastHorizon(1, 13.0, 70.0, 0.0), 'experiment' => $olderExperiment],
            [...forecastHorizon(2, 13.0, 70.0, 0.0), 'experiment' => $olderExperiment],
        ],
    ]);

    Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']);

    $data = $older->refresh()->data;
    expect($data[0]['experiment'] ?? null)->toEqual([
        'version' => 'light-v2', 'temperature' => ['low' => 12.0, 'mid' => 12.8, 'high' => 14.0],
    ]);
    expect($data[1])->not->toHaveKey('experiment');
});

it('refuses to replay when the service does not report its experiment version', function (): void {
    fakeReplayService(['http://forecast.test/health' => Http::response(['status' => 'ok', 'model' => REPLAY_MODEL, 'correction' => 3])]);
    $sensor = Sensor::factory()->create();
    replayedForecast($sensor, '2026-10-02 08:00:00');

    expect(fn (): int => Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']))
        ->toThrow(UnexpectedValueException::class, 'The forecast service does not report its experiment version');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/light-'));
});

it('reports a day whose fit failed and goes on with the next', function (): void {
    fakeReplayService([
        'http://forecast.test/light-correction' => Http::sequence()
            ->pushStatus(500)
            ->push([
                'model' => REPLAY_MODEL, 'version' => 'light-v2',
                'profile' => ['day' => '2026-10-03', 'envelope' => array_fill(0, 182, 2000.5)],
            ]),
    ]);
    $sensor = Sensor::factory()->create();
    arrivedReading($sensor, '2026-10-02 08:01:00', '2026-10-02 08:02:00');
    arrivedReading($sensor, '2026-10-03 08:01:00', '2026-10-03 08:02:00');
    $failedDay = replayedForecast($sensor, '2026-10-02 08:00:00');
    $nextDay = replayedForecast($sensor, '2026-10-03 08:00:00');
    Exceptions::fake();

    expect(Artisan::call('forecast:backfill-light', ['from' => '2026-10-02']))->toBe(0);

    Exceptions::assertReported(RequestException::class);
    expect($failedDay->refresh()->data[0])->not->toHaveKey('experiment');
    expect($nextDay->refresh()->data[0])->toHaveKey('experiment');
});
