<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\TemperatureCalibration;
use App\ValueObject\ShieldFeatures;
use Illuminate\Database\Eloquent\Builder;

final readonly class ShieldCalibrations
{
    public function newest(?int $sensorId): ?TemperatureCalibration
    {
        return $this->forSensor($sensorId)->first();
    }

    /** Newest calibration whose validation window [trained_until, validated_until) overlaps [from, until). */
    public function validatingWithin(?int $sensorId, int $from, int $until): ?TemperatureCalibration
    {
        return $this->forSensor($sensorId)
            ->where('trained_until', '<', $until)
            ->where('validated_until', '>', $from)
            ->first();
    }

    /** @return Builder<TemperatureCalibration> */
    private function forSensor(?int $sensorId): Builder
    {
        return TemperatureCalibration::query()
            ->where('sensor_id', $sensorId)
            ->where('feature_version', ShieldFeatures::VERSION)
            ->orderByDesc('id');
    }
}
