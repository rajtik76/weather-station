<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Measurement;

final readonly class ShieldFeatures
{
    public const int VERSION = 1;

    public const int WARMUP_SECONDS = 3600;

    public const int HISTORY_SECONDS = 6 * 3600;

    private const array KNOTS_LUX = [500.0, 1200.0, 1800.0];

    public function __construct(public float $latitude, public float $longitude, public float $wallAzimuth) {}

    /**
     * @param  iterable<Measurement>  $measurements
     * @return list<ShieldSample>
     */
    public function samples(iterable $measurements): array
    {
        $samples = [];
        $previous = null;
        $started = null;
        $fast = $slow = array_fill(0, count(self::KNOTS_LUX), 0.0);

        foreach ($measurements as $measurement) {
            $data = $measurement->data;
            $timestamp = $measurement->timestamp;

            if (! $data instanceof CarriesLight || ! $data->light instanceof LightWindow) {
                $previous = $started = null;

                continue;
            }

            $gap = $previous === null ? 0 : $timestamp - $previous;

            if ($previous === null || $gap <= 0 || $gap > 900) {
                $started = $timestamp;
                $fast = $slow = array_fill(0, count(self::KNOTS_LUX), 0.0);
            }

            $sun = SolarPosition::at($timestamp - 300, $this->latitude, $this->longitude);
            $facesSun = $sun->faces($this->wallAzimuth);
            $lux = $data->light->illuminance / 100;
            $drive = array_map(fn (float $knot): float => $facesSun ? max(0.0, $lux - $knot) / 1000 : 0.0, self::KNOTS_LUX);

            foreach ($drive as $index => $value) {
                $fast[$index] += (1 - exp(-max(0, $gap) / 1200)) * ($value - $fast[$index]);
                $slow[$index] += (1 - exp(-max(0, $gap) / 3600)) * ($value - $slow[$index]);
            }

            $previous = $timestamp;

            if ($timestamp - $started < self::WARMUP_SECONDS) {
                continue;
            }

            $samples[] = new ShieldSample($timestamp, $data->temperature / 100, $lux, $sun, $facesSun, [...$drive, ...$fast, ...$slow]);
        }

        return $samples;
    }
}
