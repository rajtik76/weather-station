<?php

declare(strict_types=1);

use App\Enums\RaceBlock;
use App\ValueObject\RaceDay;
use App\ValueObject\RaceStandings;

/**
 * @param  array<string, float>  $mids  by entrant; `base` becomes the base band
 * @return array{hours: int, temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}, pressure: array{low: float, mid: float, high: float}, rain_probability: float, base: array{temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}}, candidates: array<string, array{low: float, mid: float, high: float}>, shown_by?: string}
 */
function racedHorizon(int $hours, array $mids): array
{
    $band = fn (float $mid): array => ['low' => $mid - 1, 'mid' => $mid, 'high' => $mid + 1];
    $candidates = array_map($band, array_diff_key($mids, ['base' => true]));

    return [
        'hours' => $hours,
        'temperature' => $candidates['correction'] ?? $band(0.0),
        'humidity' => ['low' => 60.0, 'mid' => 70.0, 'high' => 80.0],
        'pressure' => ['low' => 970.0, 'mid' => 971.0, 'high' => 972.0],
        'rain_probability' => 0.1,
        'base' => ['temperature' => $band($mids['base']), 'humidity' => ['low' => 60.0, 'mid' => 70.0, 'high' => 80.0]],
        'candidates' => $candidates,
    ];
}

/** 07:00 UTC, 09:00 in Plzeň: +1 h is a morning target, +4 h an afternoon one. */
const RACE_ISSUED = 1791615600;

it('ranks the entrants by their summed points', function (): void {
    $standings = new RaceStandings([
        new RaceDay('8.10.2026', ['morning' => ['correction' => 2.0, 'light-v6' => 1.0, 'light-v5' => 1.5, 'base' => 3.0]]),
        new RaceDay('9.10.2026', ['morning' => ['correction' => 1.0, 'light-v6' => 1.4, 'light-v5' => 1.5, 'base' => 3.0]]),
    ]);

    expect($standings->table(RaceBlock::Morning))->toBe([
        ['name' => 'light-v6', 'total' => 2.4],
        ['name' => 'correction', 'total' => 3.0],
        ['name' => 'light-v5', 'total' => 3.0],
        ['name' => 'base', 'total' => 6.0],
    ]);
});

it('gives a tie to the simpler model, as light-v5 and light-v6 answer the base band at night', function (): void {
    $standings = new RaceStandings([new RaceDay('9.10.2026', ['night' => ['base' => 0.9, 'correction' => 1.2, 'light-v5' => 0.9, 'light-v6' => 0.9]])]);

    expect($standings->table(RaceBlock::Night))->toBe([
        ['name' => 'base', 'total' => 0.9],
        ['name' => 'light-v5', 'total' => 0.9],
        ['name' => 'light-v6', 'total' => 0.9],
        ['name' => 'correction', 'total' => 1.2],
    ]);
});

it('lists a block without points in the fallback order, correction first', function (): void {
    $standings = new RaceStandings([new RaceDay('9.10.2026', ['day' => ['correction' => 1.2, 'light-v6' => 0.9, 'light-v5' => 0.9, 'base' => 1.0]])]);

    expect($standings->table(RaceBlock::Night))->toBe([
        ['name' => 'correction', 'total' => null],
        ['name' => 'base', 'total' => null],
        ['name' => 'light-v5', 'total' => null],
        ['name' => 'light-v6', 'total' => null],
    ]);
});

it('shows each horizon the band of the leader of its target\'s block', function (): void {
    $standings = new RaceStandings([new RaceDay('9.10.2026', [
        'morning' => ['correction' => 2.0, 'light-v6' => 1.0, 'light-v5' => 1.5, 'base' => 3.0],
        'day' => ['correction' => 0.5, 'light-v6' => 0.9, 'light-v5' => 0.8, 'base' => 0.7],
    ])]);
    $mids = ['correction' => 20.0, 'light-v6' => 19.0, 'light-v5' => 21.0, 'base' => 18.0];

    $picked = $standings->pick([racedHorizon(1, $mids), racedHorizon(4, $mids)], RACE_ISSUED);

    expect([$picked[0]['shown_by'] ?? null, $picked[0]['temperature']['mid']])->toBe(['light-v6', 19.0])
        ->and([$picked[1]['shown_by'] ?? null, $picked[1]['temperature']['mid']])->toBe(['correction', 20.0]);
});

it('falls to the next entrant when the leader did not forecast the horizon', function (): void {
    $standings = new RaceStandings([new RaceDay('9.10.2026', ['morning' => ['correction' => 2.0, 'light-v6' => 1.0, 'light-v5' => 1.5, 'base' => 3.0]])]);

    $picked = $standings->pick([racedHorizon(1, ['correction' => 20.0, 'light-v5' => 21.0, 'base' => 18.0])], RACE_ISSUED);

    expect([$picked[0]['shown_by'] ?? null, $picked[0]['temperature']['mid']])->toBe(['light-v5', 21.0]);
});

it('keeps showing the correction before any day is scored', function (): void {
    $picked = new RaceStandings([])->pick([racedHorizon(1, ['correction' => 20.0, 'light-v6' => 19.0, 'light-v5' => 21.0, 'base' => 18.0])], RACE_ISSUED);

    expect([$picked[0]['shown_by'] ?? null, $picked[0]['temperature']['mid']])->toBe(['correction', 20.0]);
});
