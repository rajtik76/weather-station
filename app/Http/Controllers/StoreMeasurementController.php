<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProtocolVersion;
use App\Http\Requests\StoreMeasurementRequest;
use App\Models\Measurement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class StoreMeasurementController extends Controller
{
    public function __invoke(StoreMeasurementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $version = ProtocolVersion::from((int) $validated['protocol_version']);
        $sensorName = (string) $validated['sensor_name'];

        $rows = array_map(
            fn (array $measurement): array => [
                'sensor_name' => $sensorName,
                'protocol_version' => $version->value,
                'timestamp' => (int) $measurement['timestamp'],
                'data' => (string) $version->hydrate($measurement),
            ],
            $validated['measurements'],
        );

        $upserted = Measurement::upsert(
            values: $rows,
            uniqueBy: ['sensor_name', 'timestamp'],
            // A sensor can resend the same timestamp on a newer protocol after a firmware upgrade.
            update: ['data', 'protocol_version'],
        );

        $this->pingHeartbeat();

        return response()->json(['stored' => $upserted], JsonResponse::HTTP_CREATED);
    }

    /**
     * Tell the uptime monitor a batch arrived.
     *
     * The monitor is a push type with a one hour interval, so it reports a
     * problem once an hour passes with no upload - the station reports every
     * ten minutes, and silence means the device, its WiFi or this endpoint
     * stopped working. Sent after the response, so a slow or unreachable
     * monitor never delays the device, and swallowed on failure, because a
     * missed heartbeat is not worth failing an upload that already stored.
     */
    private function pingHeartbeat(): void
    {
        $url = config('sensor.heartbeat_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        dispatch(fn () => rescue(fn () => Http::timeout(5)->get($url)))->afterResponse();
    }
}
