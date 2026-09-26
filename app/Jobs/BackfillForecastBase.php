<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\ServiceReadings;
use App\ValueObject\ChartWindow;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;

/**
 * Fills in `base` - the forecast before the station correction - on the
 * sensor's forecasts stored before the service returned it. The base models
 * read only the last 48 hours, so the service's /base recomputes them from
 * the readings exactly as they were made; the correction learns from the
 * whole history and is not recomputed, so the stored forecast stays as it
 * was shown.
 *
 * A week of forecasts per request, with three days of readings before it.
 * Only the forecasts of the model the service runs now (`/health`) are
 * asked for: another model's base is gone with it, and asking again on
 * every run would fill nothing. A forecast is filled only when the answer
 * has every horizon it stores. Answers how many forecasts it filled in.
 *
 * @phpstan-import-type Horizon from Forecast
 * @phpstan-import-type Band from Forecast
 */
class BackfillForecastBase
{
    use Dispatchable;

    private const int SPAN_SECONDS = 7 * 86_400;

    private const int LOOKBACK_SECONDS = 3 * 86_400;

    public function __construct(public Sensor $sensor) {}

    public function handle(): int
    {
        /** @var array{model: string} $health */
        $health = Http::timeout(10)->get($this->url('health'))->throw()->json();

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
                $filled += $this->fill($span);
                $span = [];
            }

            $span[] = $forecast;
        }

        return $span === [] ? $filled : $filled + $this->fill($span);
    }

    /**
     * @param  non-empty-list<Forecast>  $span  oldest first
     */
    private function fill(array $span): int
    {
        $since = $span[0]->issued_at;
        $readings = new ServiceReadings($this->sensor->id)
            ->between($since - self::LOOKBACK_SECONDS, $span[array_key_last($span)]->issued_at + ChartWindow::STEP_SECONDS - 1);

        /** @var array{model: string, forecasts: list<array{issued_at: int, horizons: list<array{hours: int, temperature: Band, humidity: Band}>}>} $answer */
        $answer = Http::timeout(120)
            ->post($this->url('base'), [
                'longitude' => config('forecast.longitude'),
                'since' => $since,
                'readings' => $readings,
            ])
            ->throw()
            ->json();

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
                'base' => [
                    'temperature' => $byHours[$horizon['hours']]['temperature'],
                    'humidity' => $byHours[$horizon['hours']]['humidity'],
                ],
            ], $forecast->data);
            $forecast->save();
            $filled++;
        }

        return $filled;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('forecast.url'), '/').'/'.$path;
    }
}
