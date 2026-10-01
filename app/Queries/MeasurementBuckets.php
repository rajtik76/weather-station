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
 * One sensor's measurements in fixed epoch slots (PostgreSQL only); a missed slot is a row of nulls.
 * Buckets divide the epoch, not the local day, so DST never moves them. Every
 * query is scoped to the sensor, or another station averages into this line.
 *
 * @phpstan-type Bucket object{bucket: int, t_avg: ?string, h_avg: ?string, p_avg: ?string, t_min: ?string, t_max: ?string, h_min: ?string, h_max: ?string, p_min: ?string, p_max: ?string}
 * @phpstan-type NoiseBucket object{bucket: int, laeq: ?string, la10: ?string, la90: ?string, lamax: ?string, bands: ?string}
 * @phpstan-type LightBucket object{bucket: int, l_avg: ?string, l_min: int|string|null, l_max: int|string|null}
 */
final readonly class MeasurementBuckets
{
    /** Seconds a window's microphone listened; weights its noise levels. */
    private const string NOISE_SECONDS = "(data->'noise'->>'seconds')::float8";

    /** Null id matches nothing. */
    public function __construct(private ?int $sensorId) {}

    /**
     * Reads protocol keys directly, not via ProtocolVersion::hydrate(): a renamed field must change
     * this too. Extremes fall back to the value where an entry has none (V1).
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
     * Levels are hundredths of a dB: power is 10^(v / 1000). They average as energy, weighted by
     * seconds heard (50 and 60 dB make 57.4, not 55). LA10 and LA90 cannot combine across windows,
     * so wider buckets carry their time-weighted mean in dB, exact only at ten minutes.
     * Bands are averaged per bucket and band, `WITH ORDINALITY` keeping their order.
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
     * Illuminance in hundredths of a lux: mean of window means, extremes of extremes.
     *
     * @return Collection<int, LightBucket>
     */
    public function light(ChartWindow $window): Collection
    {
        $light = $this->bucketed($window)
            ->selectRaw("AVG((data->>'illuminance')::bigint) AS l_avg")
            ->selectRaw("MIN((data->>'illuminance_min')::bigint) AS l_min")
            ->selectRaw("MAX((data->>'illuminance_max')::bigint) AS l_max")
            ->whereRaw("data->'illuminance' IS NOT NULL");

        /** @var Collection<int, LightBucket> $buckets */
        $buckets = $this->slots($window)
            ->leftJoinSub($light, 'light', 'light.bucket', '=', 'slot.bucket')
            ->addSelect(['l_avg', 'l_min', 'l_max'])
            ->get();

        return $buckets;
    }

    /**
     * Each noise window's own spectrum, not averaged: a shower would vanish into dry
     * windows on a wide bucket. Bands in hundredths of a dB.
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
     * First reading of every bucket. Grouping, not `timestamp % bucket < STEP`:
     * stamps sit minutes off the slot and drift, so a phase test misses rows.
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

    /** Every slot of the window, holes included. */
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
     * Rows over whole edge buckets, so an edge aggregate does not depend on where the window opened.
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
     * Seconds-weighted energy mean; levels in hundredths of a dB.
     *
     * @param  literal-string  $level
     * @return literal-string
     */
    private function energyMean(string $level): string
    {
        return '1000 * LOG(SUM('.self::NOISE_SECONDS.' * POWER(10, ('.$level.')::float8 / 1000)) / SUM('.self::NOISE_SECONDS.'))';
    }

    /**
     * Time-weighted mean in dB, for the percentiles.
     *
     * @param  literal-string  $level
     * @return literal-string
     */
    private function timeMean(string $level): string
    {
        return 'SUM('.self::NOISE_SECONDS.' * ('.$level.')::float8) / SUM('.self::NOISE_SECONDS.')';
    }
}
