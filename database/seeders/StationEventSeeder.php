<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sensor;
use App\Models\StationEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * A handful of events on each station's last week, so the charts show their
 * lines and icons after a seed: two further back, three over the last three
 * days where the first station has noise, and one forty minutes after the
 * newest, so two icons sit side by side.
 */
class StationEventSeeder extends Seeder
{
    private const int STEP_SECONDS = 600;

    /** @var list<array{title: string, color: ?string}> */
    private const array EVENTS = [
        ['title' => 'Radiation shield cleaned', 'color' => '#71717a'],
        ['title' => 'Firmware updated over the air', 'color' => '#8b5cf6'],
        ['title' => 'Station moved half a metre away from the wall', 'color' => '#f59e0b'],
        ['title' => 'Microphone windscreen replaced', 'color' => '#10b981'],
        ['title' => 'Power cut', 'color' => '#ef4444'],
        ['title' => 'Router restarted', 'color' => null],
        ['title' => 'Balcony door left open', 'color' => '#0ea5e9'],
        ['title' => 'Sensor cable re-soldered', 'color' => '#ec4899'],
    ];

    /** How long after the newest event its companion comes. */
    private const int PAIR_GAP_SECONDS = 40 * 60;

    public function run(): void
    {
        // Fixed seed, so a reseed marks the same moments.
        mt_srand(2609);

        foreach (Sensor::query()->get() as $sensor) {
            $newest = $sensor->measurements()->max('timestamp');

            if ($newest === null) {
                continue;
            }

            $hoursBack = [
                mt_rand(72, 160),
                mt_rand(72, 160),
                mt_rand(2, 70),
                mt_rand(2, 70),
                mt_rand(2, 70),
            ];
            $times = array_map(fn (int $hours): int => $this->slot($newest - $hours * 3600), $hoursBack);
            $times[] = max($times) + self::PAIR_GAP_SECONDS;

            $events = self::EVENTS;
            shuffle($events);

            foreach ($times as $index => $time) {
                StationEvent::factory()->for($sensor)->create([
                    'occurred_at' => Date::createFromTimestamp($time, 'UTC'),
                    ...$events[$index],
                ]);
            }
        }
    }

    private function slot(int $timestamp): int
    {
        return intdiv($timestamp, self::STEP_SECONDS) * self::STEP_SECONDS;
    }
}
