<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\Models\TemperatureCalibration;
use App\ValueObject\ShieldFeatures;

/** @phpstan-type Correction array{timestamp: int, measured: float, estimated: ?float, correction: ?float, calibration_id: int} */
final readonly class CorrectedTemperature
{
    public function __construct(private TemperatureDataset $dataset) {}

    /** @return Correction|null */
    public function at(int $sensorId, int $timestamp): ?array
    {
        return $this->between($sensorId, $timestamp, $timestamp + 1)[$timestamp] ?? null;
    }

    /**
     * @param  ?list<Measurement>  $measurements  Preloaded readings covering [from - HISTORY_SECONDS, until); loaded here when null.
     * @return array<int, Correction>
     */
    public function between(int $sensorId, int $from, int $until, ?array $measurements = null): array
    {
        $calibrations = TemperatureCalibration::query()
            ->where('sensor_id', $sensorId)
            ->where('accepted', true)
            ->where('feature_version', ShieldFeatures::VERSION)
            ->where('validated_until', '<', $until)
            ->orderByDesc('id')->get();
        $results = [];
        $claimedFrom = $until;

        foreach ($calibrations as $calibration) {
            $start = max($from, $calibration->validated_until);
            $end = $claimedFrom;
            $claimedFrom = min($claimedFrom, $calibration->validated_until);

            if ($start >= $end) {
                continue;
            }

            $model = $calibration->model();
            $historyFrom = max($calibration->trained_from, $start - ShieldFeatures::HISTORY_SECONDS);
            $history = $measurements === null
                ? $this->dataset->history($sensorId, $historyFrom, $end)
                : array_values(array_filter($measurements, fn (Measurement $measurement): bool => $measurement->timestamp >= $historyFrom && $measurement->timestamp < $end));

            foreach ($calibration->features()->samples($history) as $sample) {
                if ($sample->timestamp < $start) {
                    continue;
                }

                $heating = $model->heating($sample);
                $results[$sample->timestamp] = [
                    'timestamp' => $sample->timestamp,
                    'measured' => $sample->measured,
                    'estimated' => $heating === null ? null : $sample->measured - $heating,
                    'correction' => $heating,
                    'calibration_id' => $calibration->id,
                ];
            }
        }

        ksort($results);

        return $results;
    }
}
