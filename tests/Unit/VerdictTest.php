<?php

declare(strict_types=1);

use App\ValueObject\DayScore;
use App\ValueObject\HourOfDayScore;
use App\ValueObject\ScoreFigures;
use App\ValueObject\Verdict;

/**
 * @return array{hours: int, days: list<DayScore>, corrected: ScoreFigures, base: ?ScoreFigures, shown: ScoreFigures, rain: array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}, byHour: array<string, list<HourOfDayScore>>}
 */
function verdictScore(int $hours, ?float $skill, int $count = 200): array
{
    $figures = new ScoreFigures($count, $skill, 0.4, 0.8, 82.0, 2.5);

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
