<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\CachedForecastAccuracy;
use App\Queries\ForecastService;
use App\Queries\LightForecast;
use App\Queries\ServiceReadings;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

/**
 * Replays the light experiment on forecasts stored without its current version; returns how many were filled.
 * Fitted once per local day from the readings held at the day's first upload, issued from those held at the forecast's upload.
 * Only forecasts of the model `/health` reports with no horizon of the experiment version it reports.
 * An older version is replaced where the current one is issued; a forecast it cannot issue keeps the older one.
 *
 * @phpstan-import-type LightFitted from ForecastService
 */
class BackfillLightExperiment
{
    use Dispatchable;

    public function __construct(public Sensor $sensor, public int $since) {}

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function handle(): int
    {
        $service = ForecastService::fromConfig();
        $experiment = new LightForecast($service, $this->sensor->id);
        $readings = new ServiceReadings($this->sensor->id);
        $lookback = (int) config('forecast.lookback_hours') * 3600;

        $health = $service->health();
        $version = $health['experiment'] ?? throw new UnexpectedValueException('The forecast service does not report its experiment version');

        $missing = Forecast::query()
            ->where('sensor_id', $this->sensor->id)
            ->where('issued_at', '>=', $this->since)
            ->where('model', $health['model'])
            ->whereRaw('jsonb_array_length(data) > 0')
            ->whereRaw("NOT EXISTS (SELECT 1 FROM jsonb_array_elements(data) AS horizon WHERE horizon->'experiment'->>'version' = ?)", [$version])
            ->oldest('issued_at')
            ->get();

        /** @var array<int, LightFitted|null> $fits */
        $fits = [];
        $filled = 0;

        foreach ($missing as $forecast) {
            $windowEnd = $forecast->issued_at + ChartWindow::STEP_SECONDS - 1;
            $issuedAt = $readings->firstArrival($forecast->issued_at, $windowEnd);

            if ($issuedAt === null || $issuedAt - $forecast->issued_at > LightForecast::MAX_LAG_SECONDS) {
                continue;
            }

            $window = $readings->arrivedBy($issuedAt - $lookback, $issuedAt);

            if (! LightForecast::isLit($window)) {
                continue;
            }

            $day = LocalTime::of($forecast->issued_at)->midnight()->timestamp;

            if (! array_key_exists($day, $fits)) {
                $fits[$day] = $this->fitOn($experiment, $readings, $day, $windowEnd);
            }

            $fitted = $fits[$day];

            if ($fitted === null || $fitted['model'] !== $forecast->model) {
                continue;
            }

            try {
                $issued = $experiment->issue(['issued_at' => $forecast->issued_at, 'model' => $forecast->model], $window, $fitted);
            } catch (ConnectionException|RequestException|UnexpectedValueException $exception) {
                report($exception);

                continue;
            }

            if ($issued['horizons'] === []) {
                continue;
            }

            $forecast->update(['data' => LightForecast::onto($forecast->data, $issued)]);
            $filled++;
        }

        if ($filled > 0) {
            new CachedForecastAccuracy($this->sensor->id)->forget();
        }

        return $filled;
    }

    /**
     * Fitted as at the day's first upload: the first arrival of a reading stamped from midnight through the forecast's window.
     *
     * @return LightFitted|null
     */
    private function fitOn(LightForecast $experiment, ServiceReadings $readings, int $midnight, int $windowEnd): ?array
    {
        $firstUpload = $readings->firstArrival($midnight, $windowEnd);

        if ($firstUpload === null) {
            return null;
        }

        try {
            return $experiment->fit($readings->arrivedBy($firstUpload - (int) config('forecast.history_days') * 86400, $firstUpload));
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return null;
        }
    }
}
