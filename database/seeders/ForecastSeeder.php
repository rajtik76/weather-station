<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A forecast from each sensor's newest reading, so the dashboard's forecast
 * block shows after a seed without the forecast service running. Synthetic,
 * but shaped like the service's answer: yesterday's curve moved to today's
 * level, a range that widens with the horizon, rain that sets in close to saturation.
 */
class ForecastSeeder extends Seeder
{
    private const int STEP_SECONDS = 600;

    /** trained_at of the model bundle the rows pretend to come from. */
    private const string MODEL = '2026-09-24T08:40:43.136429+00:00';

    public function run(): void
    {
        foreach (Sensor::query()->get() as $sensor) {
            // Yesterday's curve reaches six hours past this time yesterday.
            $readings = $sensor->measurements()
                ->where('timestamp', '>=', now()->subHours(31)->getTimestamp())
                ->orderBy('timestamp')
                ->get()
                ->mapWithKeys(fn (Measurement $measurement): array => [
                    $this->slot($measurement->timestamp) => $measurement->data,
                ]);

            $issuedAt = $readings->keys()->last();
            $now = $issuedAt === null ? null : $readings->get($issuedAt);

            if ($now === null) {
                continue;
            }

            Forecast::query()->updateOrCreate(
                ['sensor_id' => $sensor->id, 'issued_at' => $issuedAt],
                [
                    'model' => self::MODEL,
                    'corrected' => true,
                    'data' => array_map(
                        fn (int $hours): array => $this->horizon($readings, $now, $issuedAt, $hours),
                        range(1, 6),
                    ),
                ],
            );
        }
    }

    /**
     * @param  Collection<int, MeasurementData>  $readings  keyed by ten-minute slot
     * @return array{hours: int, temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}, pressure: array{low: float, mid: float, high: float}, rain_probability: float}
     */
    private function horizon(Collection $readings, MeasurementData $now, int $issuedAt, int $hours): array
    {
        $yesterday = $readings->get($issuedAt - 86_400);
        $yesterdayLater = $readings->get($issuedAt - 86_400 + $hours * 3600);
        $threeHoursAgo = $readings->get($issuedAt - 3 * 3600);

        $shape = fn (string $channel): float => $yesterday === null || $yesterdayLater === null
            ? 0.0
            : 0.8 * ($yesterdayLater->{$channel} - $yesterday->{$channel}) / 100;

        $temperature = $now->temperature / 100 + $shape('temperature');
        $humidity = max(0.0, min(100.0, $now->humidity / 100 + $shape('humidity')));
        // Half the last three hours' trend, carried on.
        $trend = $threeHoursAgo === null ? 0.0 : ($now->pressure - $threeHoursAgo->pressure) / 100 / 3;
        $pressure = $now->pressure / 100 + 0.5 * $trend * $hours;

        return [
            'hours' => $hours,
            'temperature' => $this->band($temperature, 0.4 + 0.35 * $hours),
            'humidity' => $this->band($humidity, 2 + 1.5 * $hours),
            'pressure' => $this->band($pressure, 0.15 + 0.12 * $hours),
            // Nothing below 80 %, then steeply towards saturation.
            'rain_probability' => round(0.8 * max(0.0, ($humidity - 80) / 20) ** 2, 3),
        ];
    }

    /**
     * @return array{low: float, mid: float, high: float}
     */
    private function band(float $mid, float $spread): array
    {
        return ['low' => round($mid - $spread, 2), 'mid' => round($mid, 2), 'high' => round($mid + $spread, 2)];
    }

    private function slot(int $timestamp): int
    {
        return intdiv($timestamp, self::STEP_SECONDS) * self::STEP_SECONDS;
    }
}
