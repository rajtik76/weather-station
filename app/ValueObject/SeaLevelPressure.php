<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Station pressure reduced to mean sea level. Hypsometric formula with the
 * measured temperature rather than the standard atmosphere's 15 °C: a frost
 * or a heatwave is a few hPa at this height.
 */
final readonly class SeaLevelPressure
{
    /** ICAO standard atmosphere, K/m. */
    private const float LAPSE_RATE = 0.0065;

    /** Standard gravity, m/s². */
    private const float GRAVITY = 9.80665;

    /** Specific gas constant of dry air, J/(kg·K). */
    private const float GAS_CONSTANT = 287.05;

    private const float ZERO_CELSIUS = 273.15;

    private function __construct(public float $pascals) {}

    /**
     * @param  float  $altitudeMetres  Height of the sensor above sea level.
     */
    public static function reduce(MeasurementData $data, float $altitudeMetres): self
    {
        // Mean column temperature: measured plus half the standard lapse over the height.
        $columnMeanKelvin = $data->temperature / 100
            + self::ZERO_CELSIUS
            + self::LAPSE_RATE * $altitudeMetres / 2;

        return new self(
            $data->pressure * exp(
                self::GRAVITY * $altitudeMetres / (self::GAS_CONSTANT * $columnMeanKelvin)
            )
        );
    }

    public function hectopascals(int $decimals = 1): float
    {
        return round($this->pascals / 100, $decimals);
    }
}
