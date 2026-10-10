<?php

declare(strict_types=1);

use App\Models\Sensor;
use App\Queries\ModelRace;
use App\ValueObject\LocalTime;
use App\ValueObject\RaceDay;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    $this->travelTo(Date::parse('2026-10-10 12:00:00', 'UTC'));
});

/** @return list<RaceDay> */
function raceDays(Sensor $sensor): array
{
    $midnight = fn (string $date): LocalTime => LocalTime::of(Date::parse("{$date} 12:00:00", LocalTime::TIMEZONE)->getTimestamp())->midnight();

    return new ModelRace($sensor->id)->days($midnight('2026-10-08'), $midnight('2026-10-10'));
}

it('scores each entrant by its mean miss per local day and part of the day', function (): void {
    $sensor = Sensor::factory()->create();
    racedReading($sensor, '2026-10-09 07:00:00', 1200);
    racedReading($sensor, '2026-10-09 07:10:00', 1240);
    racedReading($sensor, '2026-10-09 12:00:00', 1500);
    racedReading($sensor, '2026-10-09 21:30:00', 900);
    racedForecast($sensor, '2026-10-09 06:00:00', 1, ['correction' => 13.0, 'light-v6' => 12.5, 'light-v5' => 11.0, 'base' => 14.0]);
    racedForecast($sensor, '2026-10-09 06:10:00', 1, ['correction' => 13.0, 'light-v6' => 12.0, 'light-v5' => 12.4, 'base' => 14.0]);
    racedForecast($sensor, '2026-10-09 10:00:00', 2, ['correction' => 15.5, 'light-v6' => 15.25, 'light-v5' => 15.0, 'base' => 14.0]);
    racedForecast($sensor, '2026-10-09 19:30:00', 2, ['correction' => 9.5, 'light-v6' => 9.5, 'light-v5' => 9.5, 'base' => 8.0]);

    expect(raceDays($sensor))->toEqual([
        new RaceDay('8.10.2026'),
        new RaceDay('9.10.2026', [
            'morning' => ['correction' => 0.8, 'light-v6' => 0.45, 'light-v5' => 0.5, 'base' => 1.8],
            'day' => ['correction' => 0.5, 'light-v6' => 0.25, 'light-v5' => 0.0, 'base' => 1.0],
            'night' => ['correction' => 0.5, 'light-v6' => 0.5, 'light-v5' => 0.5, 'base' => 1.0],
        ]),
    ]);
});

it('leaves out a target some entrant did not forecast or nobody measured', function (): void {
    $sensor = Sensor::factory()->create();
    racedReading($sensor, '2026-10-09 07:00:00', 1200);
    racedForecast($sensor, '2026-10-09 06:00:00', 1, ['correction' => 13.0, 'light-v5' => 11.0, 'base' => 14.0]);
    racedForecast($sensor, '2026-10-09 06:30:00', 1, ['correction' => 13.0, 'light-v6' => 12.5, 'light-v5' => 11.0, 'base' => 14.0]);

    expect(raceDays($sensor)[1]->points)->toBe([]);
});

it('scores the target\'s local day, not the day the forecast was issued', function (): void {
    $sensor = Sensor::factory()->create();
    racedReading($sensor, '2026-10-07 22:30:00', 800);
    racedForecast($sensor, '2026-10-07 20:30:00', 2, ['correction' => 9.0, 'light-v6' => 9.0, 'light-v5' => 9.0, 'base' => 9.0]);

    expect(raceDays($sensor)[0]->points)->toBe(['night' => ['base' => 1.0, 'correction' => 1.0, 'light-v5' => 1.0, 'light-v6' => 1.0]]);
});

it('scores only the chosen station\'s forecasts against its own readings', function (): void {
    $sensor = Sensor::factory()->create();
    $neighbour = Sensor::factory()->create();
    racedReading($sensor, '2026-10-09 07:00:00', 1200);
    racedReading($neighbour, '2026-10-09 07:00:00', 3000);
    racedForecast($neighbour, '2026-10-09 06:00:00', 1, ['correction' => 13.0, 'light-v6' => 12.5, 'light-v5' => 11.0, 'base' => 14.0]);

    expect(raceDays($sensor)[1]->points)->toBe([]);
});
