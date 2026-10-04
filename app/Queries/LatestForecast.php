<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\Models\Measurement;
use App\ValueObject\ChartWindow;

/** One sensor's newest forecast, hidden once stale against the newest reading or the clock, or empty. */
final readonly class LatestForecast
{
    private const int FRESH_SECONDS = Forecast::INTERVAL_SECONDS + 3 * ChartWindow::STEP_SECONDS;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    public function startingFrom(Measurement $newest): ?Forecast
    {
        $forecast = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->latest('issued_at')
            ->first();

        if ($forecast === null || $forecast->data === [] || $forecast->issued_at < max($newest->timestamp, now()->getTimestamp()) - self::FRESH_SECONDS) {
            return null;
        }

        return $forecast;
    }
}
