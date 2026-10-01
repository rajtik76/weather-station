<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StationReport;

/**
 * One sensor's newest `station` object, as the firmware sent it. By id, the
 * order they arrived in: a report describes the board when it uploaded.
 */
final readonly class LatestStationReport
{
    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    public function find(): ?StationReport
    {
        return StationReport::query()
            ->where('sensor_id', $this->sensorId)
            ->latest('id')
            ->first();
    }
}
