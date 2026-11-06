<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Models\Measurement;
use InvalidArgumentException;

/** Features are TERMS[version] in order, one column per lux knot each. */
final readonly class ShieldFeatures
{
    public const int VERSION = 2;

    /** @var list<int> */
    public const array SUPPORTED_VERSIONS = [1, 2];

    public const int WARMUP_SECONDS = 3600;

    public const int HISTORY_SECONDS = 6 * 3600;

    private const array KNOTS_LUX = [500.0, 1200.0, 1800.0];

    /** @var array<int, list<string>> */
    private const array TERMS = [1 => ['drive', 'fast', 'slow'], 2 => ['drive', 'fast', 'slow', 'low_sun', 'wind']];

    /** Terms whose coefficients and values may be negative. */
    private const array UNCONSTRAINED_TERMS = ['wind'];

    /** @var array<int, array{fast: int, slow: int}> */
    private const array TIME_CONSTANTS = [1 => ['fast' => 1200, 'slow' => 3600], 2 => ['fast' => 600, 'slow' => 1800]];

    private const float LOW_SUN_DEGREES = 20.0;

    private const int WIND_NOISE_SECONDS = 1800;

    /** 25 Hz band, dB, where the wind term is zero. */
    private const float CALM_NOISE_DB = 66.0;

    public function __construct(public float $latitude, public float $longitude, public float $wallAzimuth, public int $version = self::VERSION)
    {
        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new InvalidArgumentException('Unsupported shield feature version '.$version.'.');
        }
    }

    public static function featureCount(int $version): int
    {
        return count(self::TERMS[$version]) * count(self::KNOTS_LUX);
    }

    /** @return list<int> */
    public function unconstrained(): array
    {
        $columns = [];

        foreach (self::TERMS[$this->version] as $index => $term) {
            if (in_array($term, self::UNCONSTRAINED_TERMS, true)) {
                array_push($columns, ...range($index * count(self::KNOTS_LUX), ($index + 1) * count(self::KNOTS_LUX) - 1));
            }
        }

        return $columns;
    }

    /**
     * @param  iterable<Measurement>  $measurements
     * @return list<ShieldSample>
     */
    public function samples(iterable $measurements): array
    {
        $samples = [];
        $previous = null;
        $started = null;
        $knots = count(self::KNOTS_LUX);
        $fast = $slow = array_fill(0, $knots, 0.0);
        $windNoise = null;
        ['fast' => $fastSeconds, 'slow' => $slowSeconds] = self::TIME_CONSTANTS[$this->version];

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
                $gap = 0;
                $fast = $slow = array_fill(0, $knots, 0.0);
                $windNoise = null;
            }

            $sun = SolarPosition::at($timestamp - 300, $this->latitude, $this->longitude);
            $facesSun = $sun->faces($this->wallAzimuth);
            $lux = $data->light->illuminance / 100;
            $drive = array_map(fn (float $knot): float => $facesSun ? max(0.0, $lux - $knot) / 1000 : 0.0, self::KNOTS_LUX);

            foreach ($drive as $index => $value) {
                $fast[$index] = $this->follow($fast[$index], $value, $gap, $fastSeconds);
                $slow[$index] = $this->follow($slow[$index], $value, $gap, $slowSeconds);
            }

            $noise = $data instanceof CarriesNoise && $data->noise instanceof NoiseWindow ? $data->noise->bands[0] / 100 : null;

            if ($noise !== null) {
                $windNoise = $windNoise === null ? $noise : $this->follow($windNoise, $noise, $gap, self::WIND_NOISE_SECONDS);
            }

            $previous = $timestamp;

            if ($timestamp - $started < self::WARMUP_SECONDS) {
                continue;
            }

            $terms = [
                'drive' => $drive,
                'fast' => $fast,
                'slow' => $slow,
                'low_sun' => $this->scaled($fast, max(0.0, self::LOW_SUN_DEGREES - max($sun->elevation, 0.1)) / 10),
                'wind' => $this->scaled($fast, (($windNoise ?? self::CALM_NOISE_DB) - self::CALM_NOISE_DB) / 10),
            ];
            $features = array_merge(...array_map(fn (string $term): array => $terms[$term], self::TERMS[$this->version]));

            $samples[] = new ShieldSample($timestamp, $data->temperature / 100, $lux, $sun, $facesSun, $features);
        }

        return $samples;
    }

    private function follow(float $state, float $value, int $gap, int $seconds): float
    {
        return $state + (1 - exp(-max(0, $gap) / $seconds)) * ($value - $state);
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function scaled(array $values, float $factor): array
    {
        return array_map(fn (float $value): float => $value * $factor, $values);
    }
}
