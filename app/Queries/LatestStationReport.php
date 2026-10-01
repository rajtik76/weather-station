<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StationReport;

/** One sensor's newest `station` object, by id: a report describes the board at upload time. */
final readonly class LatestStationReport
{
    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    public function find(): ?StationReport
    {
        return StationReport::query()
            ->where('sensor_id', $this->sensorId)
            ->latest('id')
            ->first();
    }
}
