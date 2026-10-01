<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\LocalTime;
use App\ValueObject\Readout;

/**
 * One sensor's newest stored windows as they arrived, across the whole table,
 * not the chart window. Dated by `created_at`: the reading's own stamp is in
 * the JSON beside it, and a buffered batch arrives long after it was measured.
 * The packet is whatever keys its protocol version carries.
 *
 * @phpstan-type Transmission array{timestamp: int, packet: array<string, int|array<string, int|list<int>>>, at: string, ago: string, t: float, h: float, p: float}
 */
final readonly class RecentTransmissions
{
    /** With no sensor the id is null and nothing matches. */
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
