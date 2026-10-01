<?php

declare(strict_types=1);

use App\ValueObject\LocalTime;
use Illuminate\Support\Facades\Date;

it('says just now for a stamp ahead of the server rather than in the future', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // The station's clock can run ahead of the server's.
    expect(LocalTime::of(now()->addMinutes(4)->getTimestamp())->ago())->toBe('just now')
        ->and(LocalTime::of(now()->subMinutes(12)->getTimestamp())->ago())->toBe('12 minutes ago');
});
