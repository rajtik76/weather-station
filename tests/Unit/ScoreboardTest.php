<?php

declare(strict_types=1);

use App\ValueObject\DayScore;
use App\ValueObject\HourOfDayScore;
use App\ValueObject\Scoreboard;
use App\ValueObject\ScoreFigures;

function boardFigures(?float $skill, float $inRange, float $width, int $count = 10): ScoreFigures
{
    return new ScoreFigures($count, $skill, 1.0, 2.0, $inRange, $width);
}

/**
 * @return array{hours: int, days: list<DayScore>, corrected: ScoreFigures, base: ?ScoreFigures, shown: ScoreFigures, rain: array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}, byHour: array<string, list<HourOfDayScore>>}
 */
function boardScore(int $hours, ScoreFigures $shown, ?ScoreFigures $corrected = null, ?ScoreFigures $base = null): array
{
    return [
        'hours' => $hours,
        'days' => [],
        'corrected' => $corrected ?? $shown,
        'base' => $base,
        'shown' => $shown,
        'rain' => ['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
        'byHour' => [],
    ];
}

it('reads the skill and the share in range off the forecast shown', function (): void {
    [$row] = Scoreboard::of([boardScore(3, boardFigures(26.0, 78.0, 2.7, 144))]);

    expect($row)->toBe([
        'hours' => 3, 'count' => 144, 'skill' => 26.0, 'skillBar' => 26.0,
        'inRange' => 78.0, 'inRangeBar' => 78.0, 'width' => 2.7, 'baseWidth' => null,
    ]);
});

it('compares the range width with the base model on the hours that have one', function (): void {
    [$row] = Scoreboard::of([boardScore(
        6,
        shown: boardFigures(38.0, 77.0, 3.9),
        corrected: boardFigures(35.0, 76.0, 3.7),
        base: boardFigures(30.0, 70.0, 3.4),
    )]);

    expect($row['skill'])->toBe(38.0)
        ->and($row['width'])->toBe(3.7)
        ->and($row['baseWidth'])->toBe(3.4);
});

it('draws no bar for a loss and none past the track', function (): void {
    [$loss, $unscored, $huge] = Scoreboard::of([
        boardScore(1, boardFigures(-12.0, 81.0, 1.6)),
        boardScore(2, boardFigures(null, 100.0, 2.2)),
        boardScore(3, boardFigures(140.0, 100.0, 2.7)),
    ]);

    expect($loss['skillBar'])->toBe(0.0)
        ->and($unscored['skillBar'])->toBe(0.0)
        ->and($huge['skillBar'])->toBe(100.0);
});
