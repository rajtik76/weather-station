<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\Models\Measurement;
use App\ValueObject\ChartWindow;

/**
 * One sensor's newest forecast, only while it starts from the station's
 * current record: a station that went quiet has nothing to forecast from,
 * and an old forecast would read as today's. A row without hours has
 * nothing to show either.
 */
final readonly class LatestForecast
{
    /** A forecast shows only while it starts this close to the newest reading. */
    private const int FRESH_SECONDS = 3 * ChartWindow::STEP_SECONDS;

    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    public function startingFrom(Measurement $newest): ?Forecast
    {
        $forecast = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->latest('issued_at')
            ->first();

        if ($forecast === null || $forecast->data === [] || $forecast->issued_at < $newest->timestamp - self::FRESH_SECONDS) {
            return null;
        }

        return $forecast;
    }
}
