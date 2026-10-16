<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\TemperatureCalibration;
use App\ValueObject\ChmuTemperatures;
use App\ValueObject\LocalTime;
use App\ValueObject\Readout;
use App\ValueObject\ShieldFeatures;

/**
 * @phpstan-type TemperatureRow array{timestamp: int, measured: float, estimated: ?float, reference: ?float}
 * @phpstan-type CandidateRow array{timestamp: int, measured: float, estimated: ?float, reference: ?float, candidate: ?float}
 */
final readonly class TemperatureHistory
{
    public function __construct(private CorrectedTemperature $correction, private TemperatureDataset $dataset) {}

    /** @return list<TemperatureRow> */
    public function readings(?int $sensorId, int $from, int $until, bool $withReference = false): array
    {
        return array_map(fn (array $row): array => ['timestamp' => $row['timestamp'], 'measured' => $row['measured'], 'estimated' => $row['estimated'], 'reference' => $row['reference']],
            $this->rows($sensorId, $from, $until, $withReference));
    }

    /** @return list<array{0: int, 1: int, 2: ?float}> */
    public function correctedBuckets(?int $sensorId, int $from, int $until, int $step): array
    {
        $groups = [];

        $firstSlot = intdiv($from, $step) * $step;
        $lastSlot = intdiv($until - 1, $step) * $step;

        foreach ($this->readings($sensorId, $firstSlot, $lastSlot + $step) as $reading) {
            $groups[intdiv($reading['timestamp'], $step) * $step][] = $reading['estimated'];
        }

        $rows = [];

        for ($slot = $firstSlot; $slot < $until; $slot += $step) {
            $values = $groups[$slot] ?? [];
            $complete = $values !== [] && ! in_array(null, $values, true);
            $rows[] = [LocalTime::of($slot)->wallClockMs(), $slot, $complete ? array_sum($values) / count($values) : null];
        }

        return $rows;
    }

    /** @return list<CandidateRow> */
    public function candidateReadings(TemperatureCalibration $candidate): array
    {
        return $this->rows($candidate->sensor_id, $candidate->trained_until, min($candidate->validated_until, now()->getTimestamp() + 1), true, $candidate);
    }

    /** @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float}> */
    public function comparisonRows(?int $sensorId, int $from, int $until, ?TemperatureCalibration $candidate = null): array
    {
        $groups = [];
        $readUntil = min($until, now()->getTimestamp() + 1);

        foreach ($this->rows($sensorId, $from - 300, $readUntil, false, $candidate) as $reading) {
            $groups[(int) round($reading['timestamp'] / 600) * 600][] = $reading;
        }

        $references = ReferenceTemperature::query()->where('station', ChmuTemperatures::STATION)
            ->where('timestamp', '>=', $from)->where('timestamp', '<', $readUntil)
            ->pluck('temperature', 'timestamp');
        $rows = [];

        for ($slot = $from; $slot < $until; $slot += 600) {
            $readings = $groups[$slot] ?? [];
            $validating = $candidate instanceof TemperatureCalibration
                ? array_filter($readings, fn (array $reading): bool => $reading['timestamp'] >= $candidate->trained_until && $reading['timestamp'] < $candidate->validated_until)
                : [];
            $rows[] = [
                LocalTime::of($slot)->wallClockMs(),
                $this->slotMean(array_column($readings, 'estimated')),
                $references[$slot] ?? null,
                $this->slotMean(array_column($validating, 'candidate')),
            ];
        }

        return $rows;
    }

    /**
     * Candidate estimates only inside its validation window [trained_until, validated_until).
     *
     * @return list<CandidateRow>
     */
    private function rows(?int $sensorId, int $from, int $until, bool $withReference, ?TemperatureCalibration $candidate = null): array
    {
        if ($sensorId === null) {
            return [];
        }

        $history = $this->dataset->history($sensorId, $from - ShieldFeatures::HISTORY_SECONDS, $until);
        $corrected = $this->correction->between($sensorId, $from, $until, $history);
        $candidateFrom = $candidate instanceof TemperatureCalibration ? max($from, $candidate->trained_until) : $until;
        $candidateUntil = $candidate instanceof TemperatureCalibration ? min($until, $candidate->validated_until) : $until;
        $candidates = $candidate instanceof TemperatureCalibration && $candidateFrom < $candidateUntil
            ? $this->correction->byCalibration($candidate, $candidateFrom, $candidateUntil, $history) : [];
        $references = $withReference ? ReferenceTemperature::query()
            ->where('station', ChmuTemperatures::STATION)
            ->whereBetween('timestamp', [$from - 90, $until + 90])
            ->whereNotNull('temperature')->get()->mapWithKeys(fn (ReferenceTemperature $reference): array => [$reference->timestamp => $reference->temperature])->all() : [];

        return array_values(array_map(function (Measurement $measurement) use ($corrected, $candidates, $references): array {
            $timestamp = $measurement->timestamp;
            $slot = (int) round($timestamp / 600) * 600;

            return [
                'timestamp' => $timestamp,
                'measured' => Readout::of($measurement->data)->temperature(),
                'estimated' => $corrected[$timestamp]['estimated'] ?? null,
                'reference' => abs($timestamp - $slot) <= 90 ? ($references[$slot] ?? null) : null,
                'candidate' => $candidates[$timestamp]['estimated'] ?? null,
            ];
        }, array_filter($history, fn (Measurement $measurement): bool => $measurement->timestamp >= $from)));
    }

    /** @param list<?float> $values */
    private function slotMean(array $values): ?float
    {
        return $values === [] || in_array(null, $values, true) ? null : array_sum($values) / count($values);
    }
}
