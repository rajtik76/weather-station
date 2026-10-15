<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\StationEvent;
use App\Models\TemperatureCalibration;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\ShieldSample;

final readonly class CorrectedTemperature
{
    public function __construct(private TemperatureDataset $dataset) {}

    /** @return array{timestamp: int, measured: float, estimated: ?float, correction: ?float, calibration_id: int}|null */
    public function at(int $sensorId, int $timestamp): ?array
    {
        $calibration = TemperatureCalibration::query()
            ->where('sensor_id', $sensorId)
            ->where('accepted', true)
            ->where('feature_version', ShieldFeatures::VERSION)
            ->where('validated_until', '<=', $timestamp)
            ->orderByDesc('id')
            ->first();

        if ($calibration === null || StationEvent::query()->between($sensorId, $calibration->trained_from, $timestamp)->exists()) {
            return null;
        }

        $samples = $this->dataset->samples(
            $sensorId,
            max($calibration->trained_from, $timestamp - ShieldFeatures::HISTORY_SECONDS),
            $timestamp + 1,
            $calibration->features(),
        );
        $sample = $samples === [] ? null : $samples[array_key_last($samples)];

        if (! $sample instanceof ShieldSample || $sample->timestamp !== $timestamp) {
            return null;
        }

        $heating = $calibration->model()->heating($sample);

        return [
            'timestamp' => $timestamp,
            'measured' => $sample->measured,
            'estimated' => $heating === null ? null : $sample->measured - $heating,
            'correction' => $heating,
            'calibration_id' => $calibration->id,
        ];
    }
}
