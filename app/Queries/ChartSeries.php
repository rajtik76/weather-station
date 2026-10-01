<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\ChartRow;
use App\ValueObject\ChartWindow;
use App\ValueObject\LocalTime;
use App\ValueObject\RainDetector;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the chart strips draw for one sensor over one window, in the shape station-charts.js reads.
 *
 * @phpstan-import-type BucketRow from ChartRow
 * @phpstan-import-type NoiseRow from ChartRow
 * @phpstan-import-type LightRow from ChartRow
 */
final readonly class ChartSeries
{
    private MeasurementBuckets $buckets;

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId)
    {
        $this->buckets = new MeasurementBuckets($sensorId);
    }

    /**
     * Empty slots carry nulls so an outage draws as a gap; no readings at all is `[]`.
     *
     * @return list<BucketRow>
     */
    public function readings(ChartWindow $window): array
    {
        $buckets = $this->buckets->readings($window);

        if (! $buckets->contains(fn (object $bucket): bool => $bucket->t_avg !== null)) {
            return [];
        }

        return array_values($buckets->map(fn (object $bucket): array => ChartRow::bucket($bucket))->all());
    }

    /**
     * `[]` only for a sensor that never sent noise (strips then do not render);
     * otherwise holes, so the strips survive a zoom.
     *
     * @return list<NoiseRow>
     */
    public function noise(ChartWindow $window): array
    {
        if (! $this->hasEverSent('noise')) {
            return [];
        }

        return array_values($this->buckets->noise($window)->map(fn (object $bucket): array => ChartRow::noise($bucket))->all());
    }

    /**
     * Same rule as noise(): `[]` only for a sensor that never sent illuminance.
     *
     * @return list<LightRow>
     */
    public function light(ChartWindow $window): array
    {
        if (! $this->hasEverSent('illuminance')) {
            return [];
        }

        return array_values($this->buckets->light($window)->map(fn (object $bucket): array => ChartRow::light($bucket))->all());
    }

    /**
     * `[wall-clock ms, epoch]` per rainy slot. Windows are heard individually and a slot
     * is rainy if any was: RainDetector is calibrated on ten-minute windows, and averaging would fade a shower.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function rainSlots(ChartWindow $window): array
    {
        $rainy = [];

        foreach ($this->buckets->spectra($window) as $spectrum) {
            /** @var list<int> $bands */
            $bands = json_decode($spectrum->bands, true, flags: JSON_THROW_ON_ERROR);

            if (RainDetector::hearsStored($bands)) {
                $rainy[(int) $spectrum->bucket] = true;
            }
        }

        ksort($rainy);

        return array_map(
            fn (int $bucket): array => [LocalTime::of($bucket)->wallClockMs(), $bucket],
            array_keys($rainy),
        );
    }

    /** Counted in the table: the rows hold one per slot, filled or not. */
    public function count(ChartWindow $window): int
    {
        return $this->measurements()->whereBetween('timestamp', [$window->from, $window->to])->count();
    }

    /**
     * A partial index per key keeps this a lookup, not a scan.
     *
     * @param  'noise'|'illuminance'  $key
     */
    private function hasEverSent(string $key): bool
    {
        return $this->measurements()->whereRaw("data->'{$key}' IS NOT NULL")->exists();
    }

    /**
     * @return Builder<Measurement>
     */
    private function measurements(): Builder
    {
        return Measurement::query()->where('sensor_id', $this->sensorId);
    }
}
