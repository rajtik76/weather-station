<?php

declare(strict_types=1);

use App\Enums\ScorePeriod;

function pragueAt(string $moment): int
{
    return new DateTimeImmutable($moment, new DateTimeZone('Europe/Prague'))->getTimestamp();
}

it('bounds yesterday by local date, a 25-hour day included', function (): void {
    expect(ScorePeriod::Yesterday->bounds(pragueAt('2026-10-26 09:00')))->toBe([pragueAt('2026-10-25 00:00'), pragueAt('2026-10-26 00:00')])
        ->and(pragueAt('2026-10-26 00:00') - pragueAt('2026-10-25 00:00'))->toBe(25 * 3600);
});

it('bounds the week to the seven days back from now', function (): void {
    $now = pragueAt('2026-10-05 12:00');

    expect(ScorePeriod::Week->bounds($now))->toBe([$now - 7 * 86400 + 1, PHP_INT_MAX]);
});

it('leaves the month unbounded', function (): void {
    expect(ScorePeriod::Month->bounds(pragueAt('2026-10-05 12:00')))->toBe([PHP_INT_MIN, PHP_INT_MAX]);
});
