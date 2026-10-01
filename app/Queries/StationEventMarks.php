<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StationEvent;
use App\ValueObject\LocalTime;

/**
 * One sensor's events as `[wall-clock ms, title, colour]`; colour null lets the chart pick.
 *
 * @phpstan-type Mark array{0: int, 1: string, 2: ?string}
 */
final readonly class StationEventMarks
{
    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * @return list<Mark>
     */
    public function all(): array
    {
        return array_values(
            StationEvent::query()
                ->where('sensor_id', $this->sensorId)
                ->oldest('occurred_at')
                ->get()
                ->map(fn (StationEvent $event): array => [
                    LocalTime::of($event->occurred_at)->wallClockMs(),
                    $event->title,
                    $event->color,
                ])
                ->all()
        );
    }
}
