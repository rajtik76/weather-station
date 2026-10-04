<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\ForecastService;
use App\Queries\ServiceReadings;
use App\ValueObject\ChartWindow;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Fills `base` on forecasts stored without it; returns how many were filled.
 * Only forecasts of the model `/health` reports, and only when the answer covers every stored horizon.
 */
class BackfillForecastBase
{
    use Dispatchable;

    private const int SPAN_SECONDS = 7 * 86_400;

    private const int LOOKBACK_SECONDS = 3 * 86_400;

    public function __construct(public Sensor $sensor) {}

    public function handle(): int
    {
        $service = ForecastService::fromConfig();
        $health = $service->health();

        $missing = Forecast::query()
            ->where('sensor_id', $this->sensor->id)
            ->where('model', $health['model'])
            // An empty row has no horizon to fill.
            ->whereRaw('jsonb_array_length(data) > 0')
            ->whereRaw("data->0->'base' IS NULL")
            ->oldest('issued_at')
            ->get();

        $filled = 0;
        $span = [];

        foreach ($missing as $forecast) {
            if ($span !== [] && $forecast->issued_at - $span[0]->issued_at >= self::SPAN_SECONDS) {
                $filled += $this->fill($service, $span);
                $span = [];
            }

            $span[] = $forecast;
        }

        return $span === [] ? $filled : $filled + $this->fill($service, $span);
    }

    /**
     * @param  non-empty-list<Forecast>  $span  oldest first
     */
    private function fill(ForecastService $service, array $span): int
    {
        $since = $span[0]->issued_at;
        $readings = new ServiceReadings($this->sensor->id)
            ->between($since - self::LOOKBACK_SECONDS, $span[array_key_last($span)]->issued_at + ChartWindow::STEP_SECONDS - 1);

        $answer = $service->base([
            'longitude' => config('forecast.longitude'),
            'since' => $since,
            'readings' => $readings,
        ]);

        $bases = array_column($answer['forecasts'], 'horizons', 'issued_at');
        $filled = 0;

        foreach ($span as $forecast) {
            $base = $bases[$forecast->issued_at] ?? null;

            if ($base === null || $forecast->model !== $answer['model']) {
                continue;
            }

            $byHours = array_column($base, null, 'hours');

            if (array_diff(array_column($forecast->data, 'hours'), array_keys($byHours)) !== []) {
                continue;
            }

            $forecast->data = array_map(fn (array $horizon): array => [
                ...$horizon,
                'base' => array_intersect_key($byHours[$horizon['hours']], array_flip(['temperature', 'humidity', 'rain_probability'])),
            ], $forecast->data);
            $forecast->save();
            $filled++;
        }

        return $filled;
    }
}
