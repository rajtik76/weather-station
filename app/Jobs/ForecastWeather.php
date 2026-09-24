<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\Sensor;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Asks the forecast service (forecast/serve.py) for the next six hours from the
 * sensor's recent history and stores the answer. The service keeps no
 * state, so every run sends the whole window it learns the station
 * correction from.
 *
 * @phpstan-import-type Horizon from Forecast
 */
class ForecastWeather
{
    use Dispatchable;

    public function __construct(public Sensor $sensor) {}

    public function handle(): void
    {
        $readings = $this->readings();

        if ($readings === []) {
            return;
        }

        try {
            $response = Http::timeout(30)
                ->post(rtrim((string) config('forecast.url'), '/').'/forecast', [
                    'longitude' => config('forecast.longitude'),
                    'readings' => $readings,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            // A missed forecast is replaced ten minutes later; the upload must not fail.
            report($exception);

            return;
        }

        /** @var array{issued_at: int, model: string, corrected: bool, horizons: list<Horizon>} $forecast */
        $forecast = $response->json();

        Forecast::query()->updateOrCreate(
            ['sensor_id' => $this->sensor->id, 'issued_at' => $forecast['issued_at']],
            ['model' => $forecast['model'], 'corrected' => $forecast['corrected'], 'data' => $forecast['horizons']],
        );
    }

    /**
     * The window in the service's units: °C, % and hPa. Read by protocol key
     * like MeasurementBuckets, so a renamed field has to change this too.
     *
     * @return list<array{timestamp: int, temperature: float, humidity: float, pressure: float}>
     */
    private function readings(): array
    {
        $since = now()->subDays((int) config('forecast.history_days'))->getTimestamp();

        /** @var list<object{timestamp: int, temperature: int, humidity: int, pressure: int}> $rows */
        $rows = $this->sensor->measurements()
            ->toBase()
            ->where('timestamp', '>=', $since)
            ->orderBy('timestamp')
            ->select('timestamp')
            ->selectRaw("(data->>'temperature')::int AS temperature")
            ->selectRaw("(data->>'humidity')::int AS humidity")
            ->selectRaw("(data->>'pressure')::int AS pressure")
            ->get()
            ->all();

        return array_map(fn (object $row): array => [
            'timestamp' => (int) $row->timestamp,
            'temperature' => $row->temperature / 100,
            'humidity' => $row->humidity / 100,
            'pressure' => $row->pressure / 100,
        ], $rows);
    }
}
