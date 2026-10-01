<?php

declare(strict_types=1);

use App\ValueObject\ReferenceSkill;

/**
 * A score of the given horizon, its shown skill and the days as `[date, skill]`.
 *
 * @param  list<array{0: string, 1: ?float}>  $days
 * @return array{hours: int, days: list<array{0: string, 1: int, 2: ?float, 3: ?float, 4: ?float, 5: ?float, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?string, 12: ?int}>, corrected: array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}, base: null, shown: array{count: int, skill: ?float, error: ?float, naive: ?float, inRange: float, width: float}, rain: array{count: int, cases: int, chanceWhenRain: ?float, chanceWhenDry: ?float}, byHour: list<array{0: ?float, 1: int, 2: ?float, 3: ?float, 4: ?float}>}
 */
function referenceScore(int $hours, ?float $skill, array $days = []): array
{
    $figures = ['count' => 10, 'skill' => $skill, 'error' => 1.0, 'naive' => 2.0, 'inRange' => 80.0, 'width' => 2.0];

    return [
        'hours' => $hours,
        'days' => array_map(fn (array $day): array => [$day[0], 10, $day[1], 1.0, 2.0, 80.0, 2.0, null, null, null, null, null, null], $days),
        'corrected' => $figures,
        'base' => null,
        'shown' => $figures,
        'rain' => ['count' => 0, 'cases' => 0, 'chanceWhenRain' => null, 'chanceWhenDry' => null],
        'byHour' => [],
    ];
}

it('keys the reference skill by hours ahead', function (): void {
    expect(ReferenceSkill::byHorizon([referenceScore(1, 12.0), referenceScore(6, -3.0)]))->toBe([1 => 12.0, 6 => -3.0])
        ->and(ReferenceSkill::byHorizon([]))->toBe([]);
});

it('lays the same horizon\'s days onto the balcony\'s, a day it lacks as a gap', function (): void {
    $balcony = referenceScore(3, 20.0, [['23.9.2026', 10.0], ['24.9.2026', 30.0], ['25.9.2026', 25.0]]);
    $reference = [
        referenceScore(1, 40.0, [['24.9.2026', 99.0]]),
        referenceScore(3, 35.0, [['22.9.2026', 50.0], ['24.9.2026', 41.0], ['25.9.2026', null]]),
    ];

    expect(ReferenceSkill::byDay($balcony, $reference))->toBe([null, 41.0, null])
        ->and(ReferenceSkill::byDay($balcony, []))->toBe([null, null, null]);
});
