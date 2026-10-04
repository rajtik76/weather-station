<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ForecastHours;

/** One sensor's forecasts issued in the clock hour of a given one, up to it. */
final readonly class HourForecasts
{
    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * @return non-empty-list<Forecast> oldest first
     */
    public function upTo(Forecast $newest): array
    {
        $earlier = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->where('issued_at', '>=', ForecastHours::hourStart($newest->issued_at))
            ->where('issued_at', '<', $newest->issued_at)
            ->oldest('issued_at')
            ->get()
            ->filter(fn (Forecast $forecast): bool => $forecast->data !== []);

        return [...$earlier->values()->all(), $newest];
    }
}
