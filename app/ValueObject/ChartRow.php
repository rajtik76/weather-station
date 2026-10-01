<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Measurement;
use App\Queries\MeasurementBuckets;

/**
 * Positional arrays for station-charts.js, to keep the JSON small; nulls fill an empty slot.
 * Bucket row: `[wall-clock ms, t, h, p, dew point, epoch, tMin, tMax, hMin, hMax, pMin, pMax]`; a reading row stops after the epoch.
 * Noise row: `[wall-clock ms, epoch, LAeq, LA10, LA90, LAmax, 26 bands]` in dB.
 * Light row: `[wall-clock ms, epoch, lx, lx min, lx max]`.
 *
 * @phpstan-type ReadingRow array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}
 * @phpstan-type BucketRow array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}
 * @phpstan-type NoiseRow list<int|float|null>
 * @phpstan-type LightRow array{0: int, 1: int, 2: ?float, 3: ?float, 4: ?float}
 *
 * @phpstan-import-type Bucket from MeasurementBuckets
 * @phpstan-import-type NoiseBucket from MeasurementBuckets
 * @phpstan-import-type LightBucket from MeasurementBuckets
 */
final readonly class ChartRow
{
    /**
     * Averages go back through a measurement in protocol units, so reduction matches a single reading. Pressure extremes use the bucket's mean temperature.
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
     * Tenths of a dB.
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
     * Lux with two decimals.
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
