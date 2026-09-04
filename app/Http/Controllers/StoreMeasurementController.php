<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProtocolVersion;
use App\Http\Requests\StoreMeasurementRequest;
use App\Models\Measurement;
use Illuminate\Http\JsonResponse;

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

        return response()->json(['stored' => $upserted], JsonResponse::HTTP_CREATED);
    }
}
