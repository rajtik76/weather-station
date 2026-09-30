<?php

declare(strict_types=1);

use App\ValueObject\Verdict;

/**
 * A scored horizon, with the forecast as shown the only figures that matter to the verdict.
 *
 * @return array{hours: int, days: list<array{string, int, float|null, float|null, float|null, float|null, float|null, float|null, float|null, float|null, float|null, string|null, int|null}>, corrected: array{count: int, skill: float|null, error: float|null, naive: float|null, inRange: float, width: float}, base: array{count: int, skill: float|null, error: float|null, naive: float|null, inRange: float, width: float}|null, shown: array{count: int, skill: float|null, error: float|null, naive: float|null, inRange: float, width: float}, rain: array{count: int, cases: int, chanceWhenRain: float|null, chanceWhenDry: float|null}, byHour: list<array{float|null, int, float|null, float|null, float|null}>}
 */
function verdictScore(int $hours, ?float $skill, int $count = 200): array
{
    $figures = ['count' => $count, 'skill' => $skill, 'error' => 0.4, 'naive' => 0.8, 'inRange' => 82.0, 'width' => 2.5];

    return [
        'hours' => $hours,
        'days' => [],
        'corrected' => $figures,
        'base' => null,
        'shown' => $figures,
        'rain' => ['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
        'byHour' => [],
    ];
}

it('has no verdict before a forecast has come true', function (): void {
    expect(Verdict::of([]))->toBeNull();
});

it('answers with the six hour horizon, however many longer ones are scored', function (): void {
    $verdict = Verdict::of([verdictScore(1, 10.0), verdictScore(6, 40.0), verdictScore(12, 70.0)]);

    expect($verdict)->toMatchArray(['hours' => 6, 'skill' => 40.0, 'count' => 200, 'error' => 0.4, 'naive' => 0.8, 'inRange' => 82.0, 'width' => 2.5]);
});

it('falls back to the longest scored horizon while six hours has not come true', function (): void {
    $verdict = Verdict::of([verdictScore(1, 10.0), verdictScore(3, 25.0)]);

    expect($verdict)->toMatchArray(['hours' => 3, 'skill' => 25.0]);
});

it('lists every horizon beside the headline, the six included', function (): void {
    $verdict = Verdict::of([verdictScore(1, 10.0), verdictScore(6, 40.0), verdictScore(3, null)]);

    expect($verdict)->toHaveKey('horizons', [
        ['hours' => 1, 'skill' => 10.0],
        ['hours' => 6, 'skill' => 40.0],
        ['hours' => 3, 'skill' => null],
    ]);
});

it('is ready from a day of forecasts on', function (int $count, bool $ready): void {
    expect(Verdict::of([verdictScore(6, 40.0, $count)]))->toHaveKey('ready', $ready);
})->with([
    'one short of a day' => [143, false],
    'a day' => [144, true],
    'more than a day' => [500, true],
]);

it('is not ready without a skill, however many forecasts were scored', function (): void {
    expect(Verdict::of([verdictScore(6, null, 500)]))->toHaveKey('ready', false);
});
