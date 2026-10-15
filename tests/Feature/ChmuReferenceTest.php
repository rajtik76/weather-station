<?php

declare(strict_types=1);

use App\Models\ReferenceTemperature;
use App\Queries\ChmuReference;
use App\ValueObject\ChmuTemperatures;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

/** @param list<list<string|int|float|null>> $values */
function chmuDocument(array $values): string
{
    return json_encode(['data' => ['data' => [
        'header' => 'STATION,ELEMENT,DT,VAL,FLAG,QUALITY',
        'values' => $values,
    ]]], JSON_THROW_ON_ERROR);
}

it('imports only Mikulka air temperature and updates repeated observations', function (): void {
    $reference = new ChmuReference;
    $row = [ChmuTemperatures::STATION, 'T', '2026-10-01T08:00:00Z', 10.0, '', 5.0];
    $reference->import(chmuDocument([
        $row,
        [ChmuTemperatures::STATION, 'TPM', '2026-10-01T08:00:00Z', 30.0, '', 5.0],
        ['another-station', 'T', '2026-10-01T08:00:00Z', 40.0, '', 5.0],
    ]), 'original.json');
    $row[3] = 11.0;

    $reference->import(chmuDocument([$row]), 'revised.json');

    assertDatabaseCount('reference_temperatures', 1);
    assertDatabaseHas('reference_temperatures', [
        'station' => ChmuTemperatures::STATION,
        'timestamp' => strtotime('2026-10-01T08:00:00Z'),
        'temperature' => 11.0,
        'source' => 'revised.json',
    ]);
});

it('invalidates previously usable observations when their quality changes', function (int $quality, string $flag): void {
    $reference = new ChmuReference;
    $row = [ChmuTemperatures::STATION, 'T', '2026-10-01T08:00:00Z', 10.0, '', 0.0];
    $reference->import(chmuDocument([$row]), 'original.json');
    $row[4] = $flag;
    $row[5] = $quality;

    $reference->import(chmuDocument([$row]), 'revised.json');

    expect(ReferenceTemperature::query()->sole()->temperature)->toBeNull();
})->with([
    'suspect' => [1, ''],
    'poor' => [2, ''],
    'estimated' => [3, ''],
    'missing' => [4, ''],
    'flagged' => [5, 'x'],
]);

it('leaves the database unchanged when an imported document is malformed', function (): void {
    ReferenceTemperature::factory()->create(['temperature' => 12.0]);
    $json = chmuDocument([
        [ChmuTemperatures::STATION, 'T', '2026-10-01T08:00:00Z', 10.0, '', 5.0],
        [ChmuTemperatures::STATION, 'T', 'not-a-date', 11.0, '', 5.0],
    ]);

    expect(fn (): int => (new ChmuReference)->import($json, 'broken.json'))->toThrow(UnexpectedValueException::class);

    assertDatabaseCount('reference_temperatures', 1);
    expect(ReferenceTemperature::query()->sole()->temperature)->toBe(12.0);
});

it('fetches a dated daily document from the official open data endpoint', function (): void {
    Http::preventStrayRequests();
    $url = ChmuReference::BASE_URL.'/now/data/10m-'.ChmuTemperatures::STATION.'-20261001.json';
    Http::fake([$url => Http::response(chmuDocument([
        [ChmuTemperatures::STATION, 'T', '2026-10-01T08:00:00Z', 10.0, '', 5.0],
    ]))]);

    $exitCode = Artisan::call('temperature:import-reference', ['--date' => '2026-10-01']);

    expect($exitCode)->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === $url);
    assertDatabaseHas('reference_temperatures', ['temperature' => 10.0, 'source' => $url]);
});

it('does not replace references with an unsuccessful download', function (): void {
    Http::preventStrayRequests();
    $url = ChmuReference::BASE_URL.'/now/data/10m-'.ChmuTemperatures::STATION.'-20261001.json';
    Http::fake([$url => Http::response('', 404)]);
    ReferenceTemperature::factory()->create(['temperature' => 12.0]);

    expect(fn (): int => (new ChmuReference)->download('2026-10-01'))->toThrow(RequestException::class);

    assertDatabaseCount('reference_temperatures', 1);
    expect(ReferenceTemperature::query()->sole()->temperature)->toBe(12.0);
    Http::assertSentCount(1);
});

it('does not fail the scheduled import while the current day file is not published yet', function (): void {
    Http::preventStrayRequests();
    $this->travelTo(Date::parse('2026-10-15 00:15:00', 'UTC'));
    Http::fake([
        '*20261014.json' => Http::response(chmuDocument([[ChmuTemperatures::STATION, 'T', '2026-10-14T23:50:00Z', 10.0, '', 5.0]])),
        '*20261015.json' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('temperature:import-reference');

    expect($exitCode)->toBe(0);
    assertDatabaseCount('reference_temperatures', 1);
});

it('still fails the scheduled import when yesterday file is missing', function (): void {
    Http::preventStrayRequests();
    $this->travelTo(Date::parse('2026-10-15 12:15:00', 'UTC'));
    Http::fake([
        '*20261014.json' => Http::response('', 404),
        '*20261015.json' => Http::response(chmuDocument([[ChmuTemperatures::STATION, 'T', '2026-10-15T11:50:00Z', 10.0, '', 5.0]])),
    ]);

    expect(Artisan::call('temperature:import-reference'))->toBe(1);
    assertDatabaseCount('reference_temperatures', 1);
});
