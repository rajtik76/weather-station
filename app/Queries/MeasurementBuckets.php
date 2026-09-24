<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\ValueObject\ChartWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One sensor's measurements grouped into fixed slots of the epoch. PostgreSQL
 * only: `generate_series` lays out every slot and a left join puts the
 * aggregates onto it, so a missed slot is a row of nulls. Buckets divide the
 * epoch, not the local day, so DST never moves them. Every query is scoped to
 * the sensor; unscoped, another station would average into this one's line.
 *
 * @phpstan-type Bucket object{bucket: int, t_avg: ?string, h_avg: ?string, p_avg: ?string, t_min: ?string, t_max: ?string, h_min: ?string, h_max: ?string, p_min: ?string, p_max: ?string}
 * @phpstan-type NoiseBucket object{bucket: int, laeq: ?string, la10: ?string, la90: ?string, lamax: ?string, bands: ?string}
 */
final readonly class MeasurementBuckets
{
    /** How long a window's microphone listened; the weight of its noise levels. */
    private const string NOISE_SECONDS = "(data->'noise'->>'seconds')::float8";

    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId) {}

    /**
     * The blob is read by protocol key, not through ProtocolVersion::hydrate(),
     * so a protocol that renames a field has to change this query too. The
     * extremes fall back to the value where an entry has none (V1).
     *
     * @return Collection<int, Bucket>
     */
    public function readings(ChartWindow $window): Collection
    {
        $readings = $this->bucketed($window);

        foreach (['t' => 'temperature', 'h' => 'humidity', 'p' => 'pressure'] as $column => $field) {
            $readings
                ->selectRaw("AVG((data->>'{$field}')::int) AS {$column}_avg")
                ->selectRaw("MIN(COALESCE(data->>'{$field}_min', data->>'{$field}')::int) AS {$column}_min")
                ->selectRaw("MAX(COALESCE(data->>'{$field}_max', data->>'{$field}')::int) AS {$column}_max");
        }

        /** @var Collection<int, Bucket> $buckets */
        $buckets = $this->slots($window)
            ->leftJoinSub($readings, 'reading', 'reading.bucket', '=', 'slot.bucket')
            ->addSelect(['t_avg', 'h_avg', 'p_avg', 't_min', 't_max', 'h_min', 'h_max', 'p_min', 'p_max'])
            ->get();

        return $buckets;
    }

    /**
     * Same slots as readings(). Levels are in hundredths of a dB, so a
     * level's power is 10^(v / 1000) and back is 1000 log10. They average
     * as energy, weighted by the seconds each window heard: 50 and 60 dB
     * are 57.4 together, not 55, and the half minute after a boot does not
     * count as a whole window. LA10 and LA90 do not combine across windows,
     * so a bucket wider than one window carries their time-weighted mean in
     * dB - exact at ten minutes, an approximation above it.
     *
     * The bands come out of the jsonb array one row per entry and band
     * (`WITH ORDINALITY` keeps their order), are averaged per bucket and
     * band, and go back into one array per bucket in band order.
     *
     * @return Collection<int, NoiseBucket>
     */
    public function noise(ChartWindow $window): Collection
    {
        $levels = $this->bucketed($window)
            ->selectRaw($this->energyMean("data->'noise'->>'laeq'").' AS laeq')
            ->selectRaw($this->timeMean("data->'noise'->>'la10'").' AS la10')
            ->selectRaw($this->timeMean("data->'noise'->>'la90'").' AS la90')
            ->selectRaw("MAX((data->'noise'->>'lamax')::int) AS lamax")
            ->whereRaw("data->'noise' IS NOT NULL");

        $perBand = $this->bucketed($window, "measurements, jsonb_array_elements_text(measurements.data->'noise'->'bands') WITH ORDINALITY AS band (level, position)")
            ->selectRaw('position')
            ->selectRaw($this->energyMean('level').' AS level')
            ->groupByRaw('2');

        $bands = DB::query()
            ->fromSub($perBand, 'band')
            ->select('bucket')
            ->selectRaw('json_agg(level ORDER BY position) AS bands')
            ->groupBy('bucket');

        /** @var Collection<int, NoiseBucket> $buckets */
        $buckets = $this->slots($window)
            ->leftJoinSub($levels, 'level', 'level.bucket', '=', 'slot.bucket')
            ->leftJoinSub($bands, 'band', 'band.bucket', '=', 'slot.bucket')
            ->addSelect(['laeq', 'la10', 'la90', 'lamax', 'bands'])
            ->get();

        return $buckets;
    }

    /**
     * Every noise window's own spectrum with the bucket it falls in, not
     * averaged: rain is heard per window, and a shower averaged with the dry
     * windows around it would vanish on a wide bucket. Same bounds as noise().
     * Bands in hundredths of a dB, as the jsonb array holds them.
     *
     * @return Collection<int, object{bucket: int, bands: string}>
     */
    public function spectra(ChartWindow $window): Collection
    {
        $step = $window->range()->bucketSeconds();

        /** @var Collection<int, object{bucket: int, bands: string}> $spectra */
        $spectra = DB::query()
            ->from('measurements')
            ->selectRaw('(timestamp / ?::int) * ?::int AS bucket', [$step, $step])
            ->selectRaw("data->'noise'->'bands' AS bands")
            ->where('sensor_id', $this->sensorId)
            ->whereBetween('timestamp', [$this->slotOf($window->from, $step), $this->slotOf($window->to, $step) + $step - 1])
            ->whereRaw("data->'noise' IS NOT NULL")
            ->orderBy('timestamp')
            ->get();

        return $spectra;
    }

    /**
     * First reading of every bucket. Grouping, not `timestamp % bucket <
     * STEP`: the station's stamps sit minutes off the slot and drift, so a
     * phase test would miss rows.
     *
     * @param  Builder<Measurement>  $query
     * @return Builder<Measurement>
     */
    public function firstPerBucket(Builder $query, int $bucketSeconds): Builder
    {
        return $query->whereIn('timestamp', function (QueryBuilder $bucket) use ($bucketSeconds): void {
            // Scoped to the sensor, or another station's earlier stamp wins the bucket.
            $bucket->selectRaw('MIN(timestamp)')
                ->from('measurements')
                ->where('sensor_id', $this->sensorId)
                ->groupByRaw('timestamp / ?', [$bucketSeconds]);
        });
    }

    /** Every slot of the window, holes included, to left-join aggregates onto. */
    private function slots(ChartWindow $window): QueryBuilder
    {
        $step = $window->range()->bucketSeconds();

        return DB::query()
            ->fromRaw('generate_series(?::int, ?::int, ?::int) AS slot (bucket)', [
                $this->slotOf($window->from, $step),
                $this->slotOf($window->to, $step),
                $step,
            ])
            ->select('slot.bucket')
            ->orderBy('slot.bucket');
    }

    /**
     * The sensor's rows over whole edge buckets - so an edge bucket's
     * aggregate does not depend on where the window opened - grouped by
     * bucket.
     *
     * @param  literal-string  $source
     */
    private function bucketed(ChartWindow $window, string $source = 'measurements'): QueryBuilder
    {
        $step = $window->range()->bucketSeconds();

        return DB::query()
            ->fromRaw($source)
            ->selectRaw('(timestamp / ?::int) * ?::int AS bucket', [$step, $step])
            ->where('sensor_id', $this->sensorId)
            ->whereBetween('timestamp', [$this->slotOf($window->from, $step), $this->slotOf($window->to, $step) + $step - 1])
            ->groupByRaw('1');
    }

    /** Start of the slot a timestamp falls in. */
    private function slotOf(int $timestamp, int $step): int
    {
        return intdiv($timestamp, $step) * $step;
    }

    /**
     * Energy mean of a level in hundredths of a dB, weighted by the seconds
     * each window heard.
     *
     * @param  literal-string  $level
     * @return literal-string
     */
    private function energyMean(string $level): string
    {
        return '1000 * LOG(SUM('.self::NOISE_SECONDS.' * POWER(10, ('.$level.')::float8 / 1000)) / SUM('.self::NOISE_SECONDS.'))';
    }

    /**
     * Time-weighted mean of a level in dB, for the percentiles.
     *
     * @param  literal-string  $level
     * @return literal-string
     */
    private function timeMean(string $level): string
    {
        return 'SUM('.self::NOISE_SECONDS.' * ('.$level.')::float8) / SUM('.self::NOISE_SECONDS.')';
    }
}
