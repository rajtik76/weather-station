<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Synthetic forecasts shaped like the service's answer, without the service running.
 *
 * @phpstan-import-type Horizon from Forecast
 */
class ForecastSeeder extends Seeder
{
    private const int STEP_SECONDS = 600;

    /** trained_at of the model bundle the older rows pretend to come from. */
    private const string PREVIOUS_MODEL = '2026-09-10T07:12:05.402113+00:00';

    private const int HISTORY_HOURS = 14 * 24;

    private const int RETRAINED_HOURS_AGO = 5 * 24;

    private const int CORRECTION_CHANGED_HOURS_AGO = 2 * 24;

    private const int LEARNING_HOURS = 10 * 24;

    public function run(): void
    {
        foreach (Sensor::query()->get() as $sensor) {
            // Yesterday's curve must reach six hours past this time yesterday.
            $readings = $sensor->measurements()
                ->where('timestamp', '>=', now()->subHours(self::HISTORY_HOURS + 31)->getTimestamp())
                ->orderBy('timestamp')
                ->get()
                ->mapWithKeys(fn (Measurement $measurement): array => [
                    $this->slot($measurement->timestamp) => $measurement->data,
                ]);

            $newest = $readings->keys()->last();

            if ($newest === null) {
                continue;
            }

            $since = $newest - self::HISTORY_HOURS * 3600;
            $retrainedAt = $newest - self::RETRAINED_HOURS_AGO * 3600;
            // Trained the hour before it took over.
            $model = Date::createFromTimestamp($retrainedAt - 3600, 'UTC')->toIso8601String();
            $issues = $readings->keys()
                ->filter(fn (int $slot): bool => $slot >= $since && $slot % 3600 === 0)
                ->push($newest)
                ->unique();

            foreach ($issues as $issuedAt) {
                $now = $readings->get($issuedAt);

                if ($now === null) {
                    continue;
                }

                $learnt = min(1.0, ($issuedAt - $since) / (self::LEARNING_HOURS * 3600));

                Forecast::query()->updateOrCreate(
                    ['sensor_id' => $sensor->id, 'issued_at' => $issuedAt],
                    [
                        'model' => $issuedAt >= $retrainedAt ? $model : self::PREVIOUS_MODEL,
                        'corrected' => true,
                        'correction' => $issuedAt >= $newest - self::CORRECTION_CHANGED_HOURS_AGO * 3600 ? 2 : 1,
                        'data' => array_map(
                            fn (int $hours): array => $this->horizon($readings, $now, $issuedAt, $hours, $learnt),
                            range(1, 6),
                        ),
                    ],
                );
            }
        }
    }

    /**
     * @param  Collection<int, MeasurementData>  $readings  keyed by ten-minute slot
     * @param  float  $learnt  how much of what it can the correction has learnt, 0-1
     * @return Horizon
     */
    private function horizon(Collection $readings, MeasurementData $now, int $issuedAt, int $hours, float $learnt): array
    {
        $yesterday = $readings->get($issuedAt - 86_400);
        $yesterdayLater = $readings->get($issuedAt - 86_400 + $hours * 3600);
        $threeHoursAgo = $readings->get($issuedAt - 3 * 3600);

        $shape = fn (string $channel, float $follows): float => $yesterday === null || $yesterdayLater === null
            ? 0.0
            : $follows * ($yesterdayLater->{$channel} - $yesterday->{$channel}) / 100;
        // The correction pulls the loose base closer as it learns.
        $follows = 0.4 + 0.4 * $learnt;

        $temperature = $now->temperature / 100 + $shape('temperature', $follows);
        $humidity = max(0.0, min(100.0, $now->humidity / 100 + $shape('humidity', $follows)));
        $baseTemperature = $now->temperature / 100 + $shape('temperature', 0.4);
        $baseHumidity = max(0.0, min(100.0, $now->humidity / 100 + $shape('humidity', 0.4)));
        $trend = $threeHoursAgo === null ? 0.0 : ($now->pressure - $threeHoursAgo->pressure) / 100 / 3;
        $pressure = $now->pressure / 100 + 0.5 * $trend * $hours;
        $baseSpread = 0.6 + 0.5 * $hours;

        return [
            'hours' => $hours,
            'temperature' => $this->band($temperature, $baseSpread - $learnt * (0.2 + 0.15 * $hours)),
            'humidity' => $this->band($humidity, 2 + 1.5 * $hours),
            'pressure' => $this->band($pressure, 0.15 + 0.12 * $hours),
            // Nothing below 80 %, then steeply towards saturation.
            'rain_probability' => round(0.8 * max(0.0, ($humidity - 80) / 20) ** 2, 3),
            'base' => [
                'temperature' => $this->band($baseTemperature, $baseSpread),
                'humidity' => $this->band($baseHumidity, 3 + 2 * $hours),
            ],
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
