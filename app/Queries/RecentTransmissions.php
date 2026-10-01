<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\LocalTime;
use App\ValueObject\Readout;

/**
 * One sensor's newest windows as they arrived, dated by `created_at` since a buffered batch lands late.
 *
 * @phpstan-type Transmission array{timestamp: int, packet: array<string, int|array<string, int|list<int>>>, at: string, ago: string, t: float, h: float, p: float}
 */
final readonly class RecentTransmissions
{
    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * @return list<Transmission>
     */
    public function latest(int $count): array
    {
        return array_values(
            Measurement::query()
                ->where('sensor_id', $this->sensorId)
                ->orderByDesc('timestamp')
                ->limit($count)
                ->get()
                ->map(function (Measurement $measurement): array {
                    $readout = Readout::of($measurement->data);

                    return [
                        'timestamp' => $measurement->timestamp,
                        'packet' => $measurement->data->jsonSerialize(),
                        // Rows written before the column existed.
                        ...LocalTime::of($measurement->created_at?->getTimestamp() ?? $measurement->timestamp)->forHumans(),
                        't' => $readout->temperature(),
                        'h' => $readout->humidity(),
                        'p' => $readout->pressure(),
                    ];
                })
                ->all()
        );
    }
}
