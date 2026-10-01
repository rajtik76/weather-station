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
 * What the chart strips draw for one sensor over one window: the weather,
 * noise and light buckets as station-charts.js reads them, the slots the
 * microphone heard rain in, and how many records the window holds.
 *
 * @phpstan-import-type BucketRow from ChartRow
 * @phpstan-import-type NoiseRow from ChartRow
 * @phpstan-import-type LightRow from ChartRow
 */
final readonly class ChartSeries
{
    private MeasurementBuckets $buckets;

    /** With no sensor the id is null and nothing matches. */
    public function __construct(private ?int $sensorId)
    {
        $this->buckets = new MeasurementBuckets($sensorId);
    }

    /**
     * Every slot is a row; an empty one carries nulls so an outage draws as a
     * gap, not a line across it. A window with no readings at all is `[]`.
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
     * Protocol 3's noise over the same buckets as the readings. `[]` only for
     * a sensor that never sent any, and then the noise strips do not render;
     * a window before or between its noise keeps the strips, with holes, so
     * they do not vanish on a zoom.
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
     * Protocol 4's illuminance, by the same rule as noise(): `[]` only for a
     * sensor that never sent any (no VEML7700).
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
     * The slots the microphone heard rain in, as `[wall-clock ms, epoch]` -
     * the waterfall marks them. Each window is heard on its own and a slot is
     * rainy when any of its windows was: RainDetector is calibrated on
     * ten-minute windows, and on an averaged hour a shower would fade into
     * the dry windows beside it.
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

    /** Counted in the table, not off the rows, which have one per slot whether filled or not. */
    public function count(ChartWindow $window): int
    {
        return $this->measurements()->whereBetween('timestamp', [$window->from, $window->to])->count();
    }

    /**
     * A partial index per key keeps this a lookup, not a scan of the sensor's history.
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
