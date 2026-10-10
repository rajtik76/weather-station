<?php

declare(strict_types=1);

use App\Enums\RaceBlock;
use App\Models\Sensor;
use App\Queries\CachedModelRace;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-10-10 12:00:00', 'UTC'));
});

/** light-v6 the closest at 09:00 local on the given day. */
function morningOf(Sensor $sensor, string $date): void
{
    racedReading($sensor, "{$date} 07:00:00", 1200);
    racedForecast($sensor, "{$date} 06:00:00", 1, ['correction' => 13.0, 'light-v6' => 12.5, 'light-v5' => 11.0, 'base' => 14.0]);
}

/** @return list<string> */
function morningOrder(Sensor $sensor): array
{
    return array_column(new CachedModelRace($sensor->id)->standings()->table(RaceBlock::Morning), 'name');
}

it('settles the standings once a day, from the fourteen whole days before it', function (): void {
    $sensor = Sensor::factory()->create();
    morningOf($sensor, '2026-09-25');
    $days = new CachedModelRace($sensor->id)->standings()->days;
    morningOf($sensor, '2026-10-09');

    expect(array_column($days, 'date'))->toBe([
        '26.9.2026', '27.9.2026', '28.9.2026', '29.9.2026', '30.9.2026', '1.10.2026', '2.10.2026',
        '3.10.2026', '4.10.2026', '5.10.2026', '6.10.2026', '7.10.2026', '8.10.2026', '9.10.2026',
    ])
        ->and(morningOrder($sensor))->toBe(['correction', 'base', 'light-v5', 'light-v6']);

    $this->travelTo(Date::parse('2026-10-10 22:30:00', 'UTC'));

    expect(morningOrder($sensor))->toBe(['light-v6', 'correction', 'light-v5', 'base']);
});

it('works the standings out again once forgotten', function (): void {
    $sensor = Sensor::factory()->create();
    morningOrder($sensor);
    morningOf($sensor, '2026-10-09');

    new CachedModelRace($sensor->id)->forget();

    expect(morningOrder($sensor))->toBe(['light-v6', 'correction', 'light-v5', 'base']);
});
