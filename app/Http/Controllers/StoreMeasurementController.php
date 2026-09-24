<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProtocolVersion;
use App\Http\Requests\StoreMeasurementRequest;
use App\Jobs\ForecastWeather;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class StoreMeasurementController extends Controller
{
    public function __invoke(StoreMeasurementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $version = ProtocolVersion::from((int) $validated['protocol_version']);
        $sensor = Sensor::query()->firstOrCreate(['name' => (string) $validated['sensor_name']]);

        $rows = array_map(
            fn (array $measurement): array => [
                'sensor_id' => $sensor->id,
                'protocol_version' => $version->value,
                'timestamp' => (int) $measurement['timestamp'],
                'data' => (string) $version->hydrate($measurement),
            ],
            $validated['measurements'],
        );

        $upserted = Measurement::upsert(
            values: $rows,
            uniqueBy: ['sensor_id', 'timestamp'],
            // A window may be resent under a newer protocol after a firmware upgrade.
            update: ['data', 'protocol_version'],
        );

        $station = $request->stationReport();

        if ($station !== null) {
            StationReport::query()->create([
                'sensor_id' => $sensor->id,
                'data' => $station,
            ]);
        }

        $this->pingHeartbeat();
        $this->forecast($sensor);

        return response()->json(['stored' => $upserted], JsonResponse::HTTP_CREATED);
    }

    /** After the response, so a slow monitor never delays the device; swallowed on failure. */
    private function pingHeartbeat(): void
    {
        $url = config('sensor.heartbeat_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        dispatch(fn () => rescue(fn () => Http::timeout(5)->get($url)))->afterResponse();
    }

    /** After the response too: the service takes about a second. Unset URL means no forecasts. */
    private function forecast(Sensor $sensor): void
    {
        $url = config('forecast.url');

        if (! is_string($url) || $url === '') {
            return;
        }

        ForecastWeather::dispatchAfterResponse($sensor);
    }
}
