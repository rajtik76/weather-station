<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Forecast;
use App\ValueObject\ForecastHours;
use App\ValueObject\IssuedForecast;

/** One sensor's forecasts issued in the clock hour of a given one, up to it. */
final readonly class HourForecasts
{
    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * @return list<IssuedForecast> oldest first, without forecasts that have no horizons
     */
    public function upTo(Forecast $newest): array
    {
        $earlier = Forecast::query()
            ->where('sensor_id', $this->sensorId)
            ->where('issued_at', '>=', ForecastHours::hourStart($newest->issued_at))
            ->where('issued_at', '<', $newest->issued_at)
            ->oldest('issued_at')
            ->get()
            ->all();
        $issued = [];

        foreach ([...$earlier, $newest] as $forecast) {
            if ($forecast->data !== []) {
                $issued[] = new IssuedForecast($forecast->issued_at, $forecast->data);
            }
        }

        return $issued;
    }
}
