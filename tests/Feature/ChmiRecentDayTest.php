<?php

declare(strict_types=1);

use App\Queries\ChmiRecentDay;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/**
 * A ČHMÚ day file holding the given `[element, UTC time, value, quality]` rows.
 *
 * @param  list<array{0: string, 1: string, 2: int|float|string|null, 3: ?float}>  $rows
 * @return array<string, mixed>
 */
function chmiDayFile(array $rows): array
{
    return ['data' => ['data' => [
        'header' => 'STATION,ELEMENT,DT,VAL,FLAG,QUALITY',
        'values' => array_map(fn (array $row): array => ['0-20000-0-11450', $row[0], $row[1], $row[2], '', $row[3]], $rows),
    ]]];
}

it('reads a day of windows in the service\'s units, oldest first', function (): void {
    Http::fake(['https://chmi.test/10min/10m-0-20000-0-11450-20260930.json' => Http::response(chmiDayFile([
        ['T', '2026-09-30T00:10:00Z', 11.2, 5.0],
        ['H', '2026-09-30T00:10:00Z', 91, 5.0],
        ['P', '2026-09-30T00:10:00Z', 972.4, 5.0],
        ['T', '2026-09-30T00:00:00Z', 11.4, 0.0],
        ['H', '2026-09-30T00:00:00Z', 90, 0.0],
        ['P', '2026-09-30T00:00:00Z', 972.5, 0.0],
        ['SRA10M', '2026-09-30T00:00:00Z', 0.2, 0.0],
        // Elements the service does not read.
        ['TMA', '2026-09-30T00:00:00Z', 11.9, 0.0],
        ['RGLB10', '2026-09-30T00:00:00Z', 0, 0.0],
    ]))]);

    $readings = new ChmiRecentDay('https://chmi.test/10min/', '0-20000-0-11450')->readings(Date::parse('2026-09-30', 'UTC'));

    expect($readings)->toBe([
        ['timestamp' => 1790726400, 'temperature' => 11.4, 'humidity' => 90.0, 'pressure' => 972.5, 'rain' => 0.2],
        ['timestamp' => 1790727000, 'temperature' => 11.2, 'humidity' => 91.0, 'pressure' => 972.4, 'rain' => null],
    ]);
});

it('leaves out a value of a quality the models were not trained on, and its window with it', function (): void {
    Http::fake(['https://chmi.test/*' => Http::response(chmiDayFile([
        ['T', '2026-09-30T00:00:00Z', 11.4, 1.0],
        ['H', '2026-09-30T00:00:00Z', 90, 0.0],
        ['P', '2026-09-30T00:00:00Z', 972.5, 0.0],
        ['T', '2026-09-30T00:10:00Z', 11.2, 3.0],
        ['H', '2026-09-30T00:10:00Z', 91, 3.0],
        ['P', '2026-09-30T00:10:00Z', '#####', 3.0],
        ['T', '2026-09-30T00:20:00Z', 11.0, 3.0],
        ['H', '2026-09-30T00:20:00Z', 92, 3.0],
        ['P', '2026-09-30T00:20:00Z', 972.3, 3.0],
        ['SRA10M', '2026-09-30T00:20:00Z', 0.0, 2.0],
        // No quality at all is not a good one.
        ['T', '2026-09-30T00:30:00Z', 10.8, null],
        ['H', '2026-09-30T00:30:00Z', 93, 0.0],
        ['P', '2026-09-30T00:30:00Z', 972.2, 0.0],
    ]))]);

    $readings = new ChmiRecentDay('https://chmi.test/10min', '0-20000-0-11450')->readings(Date::parse('2026-09-30', 'UTC'));

    expect($readings)->toBe([
        ['timestamp' => 1790727600, 'temperature' => 11.0, 'humidity' => 92.0, 'pressure' => 972.3, 'rain' => null],
    ]);
});

it('reads a day not published yet as empty', function (): void {
    Http::fake(['https://chmi.test/*' => Http::response('Not Found', 404)]);

    expect(new ChmiRecentDay('https://chmi.test/10min', '0-20000-0-11450')->readings(Date::parse('2026-10-01', 'UTC')))->toBe([]);
});
