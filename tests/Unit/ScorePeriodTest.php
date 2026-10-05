<?php

declare(strict_types=1);

use App\Enums\ScorePeriod;

function pragueAt(string $moment): int
{
    return new DateTimeImmutable($moment, new DateTimeZone('Europe/Prague'))->getTimestamp();
}

it('holds yesterday by local date, a 25-hour day included', function (string $target, bool $held): void {
    expect(ScorePeriod::Yesterday->holds(pragueAt($target), pragueAt('2026-10-26 09:00')))->toBe($held);
})->with([
    'its first slot' => ['2026-10-25 00:00', true],
    'its last slot' => ['2026-10-25 23:50', true],
    'the day before' => ['2026-10-24 23:50', false],
    'today' => ['2026-10-26 00:00', false],
]);

it('holds the last seven days back from the newest slot', function (): void {
    $latest = pragueAt('2026-10-05 12:00');

    expect(ScorePeriod::Week->holds($latest - 7 * 86400 + 600, $latest))->toBeTrue()
        ->and(ScorePeriod::Week->holds($latest - 7 * 86400, $latest))->toBeFalse();
});

it('holds everything scored for the month', function (): void {
    expect(ScorePeriod::Month->holds(pragueAt('2026-01-01 00:00'), pragueAt('2026-10-05 12:00')))->toBeTrue();
});
