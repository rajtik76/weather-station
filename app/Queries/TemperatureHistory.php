<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\ValueObject\ChmuTemperatures;
use App\ValueObject\LocalTime;
use App\ValueObject\Readout;
use App\ValueObject\ShieldFeatures;

/** @phpstan-type TemperatureRow array{timestamp: int, measured: float, estimated: ?float, reference: ?float} */
final readonly class TemperatureHistory
{
    public function __construct(private CorrectedTemperature $correction, private TemperatureDataset $dataset) {}

    /** @return list<TemperatureRow> */
    public function readings(?int $sensorId, int $from, int $until, bool $withReference = false): array
    {
        if ($sensorId === null) {
            return [];
        }

        $history = $this->dataset->history($sensorId, $from - ShieldFeatures::HISTORY_SECONDS, $until);
        $corrected = $this->correction->between($sensorId, $from, $until, $history);
        $references = $withReference ? ReferenceTemperature::query()
            ->where('station', ChmuTemperatures::STATION)
            ->whereBetween('timestamp', [$from - 90, $until + 90])
            ->whereNotNull('temperature')->get()->mapWithKeys(fn (ReferenceTemperature $reference): array => [$reference->timestamp => $reference->temperature])->all() : [];

        return array_values(array_map(function (Measurement $measurement) use ($corrected, $references): array {
            $timestamp = $measurement->timestamp;
            $slot = (int) round($timestamp / 600) * 600;

            return [
                'timestamp' => $timestamp,
                'measured' => Readout::of($measurement->data)->temperature(),
                'estimated' => $corrected[$timestamp]['estimated'] ?? null,
                'reference' => abs($timestamp - $slot) <= 90 ? ($references[$slot] ?? null) : null,
            ];
        }, array_filter($history, fn (Measurement $measurement): bool => $measurement->timestamp >= $from)));
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

    /** @return list<array{0: int, 1: ?float, 2: ?float}> */
    public function comparisonRows(?int $sensorId, int $from, int $until): array
    {
        $groups = [];

        foreach ($this->readings($sensorId, $from - 300, min($until, now()->getTimestamp() + 1)) as $reading) {
            $groups[(int) round($reading['timestamp'] / 600) * 600][] = $reading;
        }

        $references = ReferenceTemperature::query()->where('station', ChmuTemperatures::STATION)
            ->where('timestamp', '>=', $from)->where('timestamp', '<', min($until, now()->getTimestamp() + 1))
            ->pluck('temperature', 'timestamp');
        $rows = [];

        for ($slot = $from; $slot < $until; $slot += 600) {
            $readings = $groups[$slot] ?? [];
            $estimated = array_column($readings, 'estimated');
            $rows[] = [
                LocalTime::of($slot)->wallClockMs(),
                $estimated === [] || in_array(null, $estimated, true) ? null : array_sum($estimated) / count($estimated),
                $references[$slot] ?? null,
            ];
        }

        return $rows;
    }
}
