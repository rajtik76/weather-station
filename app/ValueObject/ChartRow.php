<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Measurement;
use App\Queries\MeasurementBuckets;

/**
 * The rows station-charts.js draws. Positional arrays to keep the JSON
 * payload small. A bucket row is `[wall-clock ms, t, h, p, dew point, epoch,
 * tMin, tMax, hMin, hMax, pMin, pMax]` with nulls for an empty slot; a
 * reading row stops after the epoch.
 *
 * @phpstan-type ReadingRow array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}
 * @phpstan-type BucketRow array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}
 * A noise row is `[wall-clock ms, epoch, LAeq, LA10, LA90, LAmax, 26 bands]` in dB,
 * nulls for a slot without noise.
 * @phpstan-type NoiseRow list<int|float|null>
 * A light row is `[wall-clock ms, epoch, lx, lx min, lx max]`, nulls for a slot without light.
 * @phpstan-type LightRow array{0: int, 1: int, 2: ?float, 3: ?float, 4: ?float}
 *
 * @phpstan-import-type Bucket from MeasurementBuckets
 * @phpstan-import-type NoiseBucket from MeasurementBuckets
 * @phpstan-import-type LightBucket from MeasurementBuckets
 */
final readonly class ChartRow
{
    /**
     * The averages go back into a measurement in protocol units so reduction
     * and dew point run through the same code as a single reading. Pressure
     * extremes are reduced with the bucket's mean temperature; the sample
     * that read them kept none of its own.
     *
     * @param  Bucket  $bucket
     * @return BucketRow
     */
    public static function bucket(object $bucket): array
    {
        $time = LocalTime::of($bucket->bucket)->wallClockMs();

        if ($bucket->t_avg === null || $bucket->h_avg === null || $bucket->p_avg === null) {
            return [$time, null, null, null, null, $bucket->bucket, null, null, null, null, null, null];
        }

        $mean = Readout::of(new MeasurementDataV1(
            temperature: (int) round((float) $bucket->t_avg),
            humidity: (int) round((float) $bucket->h_avg),
            pressure: (int) round((float) $bucket->p_avg),
        ));

        return [
            $time,
            Readout::hundredths($bucket->t_avg),
            Readout::hundredths($bucket->h_avg),
            $mean->pressure(),
            $mean->dewPoint(),
            $bucket->bucket,
            Readout::hundredths((float) $bucket->t_min),
            Readout::hundredths((float) $bucket->t_max),
            Readout::hundredths((float) $bucket->h_min),
            Readout::hundredths((float) $bucket->h_max),
            $mean->seaLevel((int) $bucket->p_min),
            $mean->seaLevel((int) $bucket->p_max),
        ];
    }

    /**
     * Tenths of a dB: the band spread is tens of dB, and the payload is 26
     * numbers a slot.
     *
     * @param  NoiseBucket  $bucket
     * @return NoiseRow
     */
    public static function noise(object $bucket): array
    {
        $time = LocalTime::of($bucket->bucket)->wallClockMs();

        if ($bucket->laeq === null || $bucket->bands === null) {
            return [$time, $bucket->bucket, ...array_fill(0, 4 + NoiseWindow::BANDS_COUNT, null)];
        }

        $decibels = fn (float|int|string|null $value): ?float => $value === null ? null : round((float) $value / 100, 1);

        /** @var list<float|int|null> $bands */
        $bands = json_decode($bucket->bands, true, flags: JSON_THROW_ON_ERROR);

        return [
            $time,
            $bucket->bucket,
            $decibels($bucket->laeq),
            $decibels($bucket->la10),
            $decibels($bucket->la90),
            $decibels($bucket->lamax),
            ...array_map($decibels, $bands),
        ];
    }

    /**
     * Lux with two decimals: dusk reads hundredths, noon tens of thousands.
     *
     * @param  LightBucket  $bucket
     * @return LightRow
     */
    public static function light(object $bucket): array
    {
        $lux = fn (int|string|null $value): ?float => $value === null ? null : round((float) $value / 100, 2);

        return [
            LocalTime::of($bucket->bucket)->wallClockMs(),
            $bucket->bucket,
            $lux($bucket->l_avg),
            $lux($bucket->l_min),
            $lux($bucket->l_max),
        ];
    }

    /**
     * A single reading, for the navigator.
     *
     * @return ReadingRow
     */
    public static function reading(Measurement $measurement): array
    {
        $readout = Readout::of($measurement->data);

        return [
            LocalTime::of($measurement->timestamp)->wallClockMs(),
            $readout->temperature(),
            $readout->humidity(),
            $readout->pressure(),
            $readout->dewPoint(),
            $measurement->timestamp,
        ];
    }
}
