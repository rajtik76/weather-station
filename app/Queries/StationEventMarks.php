<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StationEvent;
use App\ValueObject\LocalTime;

/**
 * One sensor's events as the charts mark them: `[wall-clock ms, title,
 * colour]`, all of them - there are a handful, and a mark outside the axis
 * is not drawn. Colour null means the chart picks.
 *
 * @phpstan-type Mark array{0: int, 1: string, 2: ?string}
 */
final readonly class StationEventMarks
{
    /** With no sensor the id is null and nothing matches. */
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
