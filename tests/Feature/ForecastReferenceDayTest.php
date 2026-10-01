<?php

declare(strict_types=1);

use App\Jobs\ForecastReferenceDay;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

const REFERENCE_MODEL = '2026-09-24T08:40:43.136429+00:00';

beforeEach(function (): void {
    config()->set('forecast.url', 'http://forecast.test');
    config()->set('forecast.reference.url', 'https://chmi.test/10min');
});

/**
 * Two windows of a ČHMÚ day file, 00:00 and 00:10 UTC, 12 °C and rising by a tenth.
 *
 * @return array<string, mixed>
 */
function referenceDayFile(string $date): array
{
    $values = [];

    foreach (['00:00', '00:10'] as $step => $time) {
        foreach (['T' => 12.0 + $step / 10, 'H' => 80, 'P' => 972.4, 'SRA10M' => 0.1] as $element => $value) {
            $values[] = ['0-20000-0-11450', $element, "{$date}T{$time}:00Z", $value, '', 0.0];
        }
    }

    return ['data' => ['data' => ['header' => 'STATION,ELEMENT,DT,VAL,FLAG,QUALITY', 'values' => $values]]];
}

/**
 * ČHMÚ publishing the given days, and the service answering /base for the given windows.
 *
 * @param  list<string>  $published  Y-m-d
 * @param  list<int>  $issuedAt
 */
function fakeReference(array $published, array $issuedAt): void
{
    $files = [];

    foreach ($published as $date) {
        $files['https://chmi.test/10min/10m-0-20000-0-11450-'.str_replace('-', '', $date).'.json'] = Http::response(referenceDayFile($date));
    }

    Http::fake([
        ...$files,
        'https://chmi.test/*' => Http::response('Not Found', 404),
        'http://forecast.test/base' => Http::response(referenceBaseAnswer($issuedAt)),
    ]);
}

/**
 * What /base with full answers for the given windows: one hour ahead, 12 °C.
 *
 * @param  list<int>  $issuedAt
 * @return array<string, mixed>
 */
function referenceBaseAnswer(array $issuedAt): array
{
    return [
        'model' => REFERENCE_MODEL,
        'forecasts' => array_map(fn (int $at): array => [
            'issued_at' => $at,
            'horizons' => [[
                'hours' => 1,
                'temperature' => ['low' => 11.0, 'mid' => 12.0, 'high' => 13.0],
                'humidity' => ['low' => 75.0, 'mid' => 80.0, 'high' => 85.0],
                'pressure' => ['low' => 972.0, 'mid' => 972.4, 'high' => 972.8],
                'rain_probability' => 0.05,
            ]],
        ], $issuedAt),
    ];
}

function referenceBaseRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/base'))->count();
}

it('forecasts a day of the reference station from it and the two days before', function (): void {
    $day = CarbonImmutable::parse('2026-09-30', 'UTC');
    fakeReference(['2026-09-28', '2026-09-29', '2026-09-30'], [$day->getTimestamp(), $day->getTimestamp() + 600]);

    expect(dispatch_sync(new ForecastReferenceDay($day)))->toBe(2);

    $reference = Sensor::query()->where('name', 'Plzeň-Mikulka (ČHMÚ)')->sole();
    // Stored as a station would send them: hundredths and pascals.
    $first = Measurement::query()->where('sensor_id', $reference->id)->orderBy('timestamp')->first();
    expect(Measurement::query()->where('sensor_id', $reference->id)->count())->toBe(6)
        ->and($first?->timestamp)->toBe(CarbonImmutable::parse('2026-09-28', 'UTC')->getTimestamp())
        ->and($first?->data->jsonSerialize())->toMatchArray(['temperature' => 1200, 'humidity' => 8000, 'pressure' => 97240]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/base')
        && $request['since'] === $day->getTimestamp()
        && $request['full'] === true
        && $request['longitude'] === 13.378889
        && count($request['readings']) === 6
        && $request['readings'][0]['rain'] === 0.1);

    $forecasts = Forecast::query()->where('sensor_id', $reference->id)->oldest('issued_at')->get();
    $first = $forecasts->firstOrFail();
    expect($forecasts)->toHaveCount(2)
        ->and($first->issued_at)->toBe($day->getTimestamp())
        ->and($first->model)->toBe(REFERENCE_MODEL)
        ->and($first->corrected)->toBeFalse()
        ->and($first->correction)->toBeNull()
        ->and($first->data[0]['pressure']['mid'])->toBe(972.4);
});

it('replaces a day run again instead of adding to it', function (): void {
    $day = CarbonImmutable::parse('2026-09-30', 'UTC');
    fakeReference(['2026-09-28', '2026-09-29', '2026-09-30'], [$day->getTimestamp()]);

    dispatch_sync(new ForecastReferenceDay($day));
    dispatch_sync(new ForecastReferenceDay($day));

    $reference = Sensor::findReference();
    expect(Sensor::query()->where('name', 'Plzeň-Mikulka (ČHMÚ)')->count())->toBe(1)
        ->and(Measurement::query()->where('sensor_id', $reference?->id)->count())->toBe(6)
        ->and(Forecast::query()->where('sensor_id', $reference?->id)->count())->toBe(1);
});

it('drops what ČHMÚ took back when a day is run again', function (): void {
    $day = CarbonImmutable::parse('2026-09-30', 'UTC');
    // The next night 00:10 is gone from the 30th, and the service answers one window less.
    $revised = referenceDayFile('2026-09-30');
    $revised['data']['data']['values'] = array_values(array_filter(
        $revised['data']['data']['values'],
        fn (array $row): bool => $row[2] !== '2026-09-30T00:10:00Z',
    ));
    Http::fake([
        'https://chmi.test/10min/10m-0-20000-0-11450-20260928.json' => Http::response(referenceDayFile('2026-09-28')),
        'https://chmi.test/10min/10m-0-20000-0-11450-20260929.json' => Http::response(referenceDayFile('2026-09-29')),
        'https://chmi.test/10min/10m-0-20000-0-11450-20260930.json' => Http::sequence()
            ->push(referenceDayFile('2026-09-30'))
            ->push($revised),
        'http://forecast.test/base' => Http::sequence()
            ->push(referenceBaseAnswer([$day->getTimestamp(), $day->getTimestamp() + 600]))
            ->push(referenceBaseAnswer([$day->getTimestamp()])),
    ]);

    dispatch_sync(new ForecastReferenceDay($day));

    expect(dispatch_sync(new ForecastReferenceDay($day)))->toBe(1);

    $reference = Sensor::findReference();
    expect(Measurement::query()->where('sensor_id', $reference?->id)->where('timestamp', $day->getTimestamp() + 600)->exists())->toBeFalse()
        ->and(Measurement::query()->where('sensor_id', $reference?->id)->count())->toBe(5)
        ->and(Forecast::query()->where('sensor_id', $reference?->id)->pluck('issued_at')->all())->toBe([$day->getTimestamp()]);
});

it('forecasts nothing for a day ČHMÚ has not published', function (): void {
    fakeReference(['2026-09-29'], []);

    expect(dispatch_sync(new ForecastReferenceDay(CarbonImmutable::parse('2026-09-30', 'UTC'))))->toBe(0)
        ->and(referenceBaseRequests())->toBe(0)
        ->and(Sensor::findReference())->toBeNull();
});

it('forecasts the days ending yesterday from the command', function (): void {
    $this->travelTo(Date::parse('2026-10-01 01:00:00', 'UTC'));
    fakeReference(['2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30'], []);

    expect(Artisan::call('forecast:reference', ['--days' => 2]))->toBe(0)
        ->and(Artisan::output())->toContain("2026-09-29: 0 forecasts\n2026-09-30: 0 forecasts")
        ->and(referenceBaseRequests())->toBe(2);
});

it('forecasts nothing without a service to ask', function (): void {
    config()->set('forecast.url');
    Http::fake();

    expect(Artisan::call('forecast:reference'))->toBe(0)
        ->and(Artisan::output())->toContain('FORECAST_URL is not set: there is no service to forecast with.');

    Http::assertNothingSent();
});

it('is scheduled every night after ČHMÚ has published the day', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/0 1 \* \* \*\s+php artisan forecast:reference --days=2/');
});

it('runs the scheduler in the production container, as the web server\'s user', function (): void {
    $supervisor = parse_ini_file(base_path('docker/supervisord.conf'), true, INI_SCANNER_RAW);

    expect($supervisor)->toHaveKey('program:scheduler')
        ->and($supervisor['program:scheduler']['command'] ?? null)->toStartWith('php /app/artisan schedule:work')
        ->and($supervisor['program:scheduler']['user'] ?? null)->toBe('www-data');
});
