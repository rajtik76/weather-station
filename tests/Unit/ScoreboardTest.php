<?php

declare(strict_types=1);

use App\ValueObject\Scoreboard;

/**
 * @return array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}
 */
function boardFigures(?float $skill, float $inRange, float $width, int $count = 10): array
{
    return ['count' => $count, 'skill' => $skill, 'error' => 1.0, 'naive' => 2.0, 'inRange' => $inRange, 'width' => $width];
}

/**
 * @param  array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}  $shown
 * @param  array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}|null  $corrected
 * @param  array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}|null  $base
 * @param  array{count: int, skill: ?float, versus: ?float, error: float, shownError: float, naive: float}|null  $nwp
 * @return array{hours: int, days: list<array{0: string, 1: int, 2: ?float, 3: ?float, 4: ?float, 5: ?float, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?string, 12: ?int, 13: ?float}>, corrected: array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}, base: array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}|null, shown: array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}, nwp: array{count: int, skill: ?float, versus: ?float, error: float, shownError: float, naive: float}|null, rain: array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}, byHour: list<array{0: ?float, 1: int, 2: ?float, 3: ?float, 4: ?float}>}
 */
function boardScore(int $hours, array $shown, ?array $corrected = null, ?array $base = null, ?array $nwp = null): array
{
    return [
        'hours' => $hours,
        'days' => [],
        'corrected' => $corrected ?? $shown,
        'base' => $base,
        'shown' => $shown,
        'nwp' => $nwp,
        'rain' => ['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
        'byHour' => [],
    ];
}

it('reads the skill and the share in range off the forecast shown', function (): void {
    [$row] = Scoreboard::of([boardScore(3, boardFigures(26.0, 78.0, 2.7, 144))]);

    expect($row)->toBe([
        'hours' => 3, 'count' => 144, 'skill' => 26.0, 'skillBar' => 26.0,
        'inRange' => 78.0, 'inRangeBar' => 78.0, 'width' => 2.7, 'baseWidth' => null, 'referenceSkill' => null,
        'nwpSkill' => null, 'versusNwp' => null,
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

it('sets the reference station\'s skill beside the horizon it scored', function (): void {
    [$one, $two] = Scoreboard::of(
        [boardScore(1, boardFigures(4.0, 81.0, 1.6)), boardScore(2, boardFigures(17.0, 79.0, 2.2))],
        [1 => 12.0],
    );

    expect($one['referenceSkill'])->toBe(12.0)
        ->and($two['referenceSkill'])->toBeNull();
});

it('sets the weather model\'s skill and the forecast\'s skill against it beside the horizon', function (): void {
    [$row] = Scoreboard::of([boardScore(
        6,
        boardFigures(38.0, 77.0, 3.9),
        nwp: ['count' => 10, 'skill' => 55.0, 'versus' => -20.0, 'error' => 1.2, 'shownError' => 1.44, 'naive' => 2.7],
    )]);

    expect($row['nwpSkill'])->toBe(55.0)
        ->and($row['versusNwp'])->toBe(-20.0);
});
