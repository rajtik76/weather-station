<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * A station reading reduced to mean sea level.
 *
 * The BME280 reports the pressure where it actually hangs, and at 345 m that
 * is some 40 hPa below what a forecast or a neighbouring station quotes. Every
 * published figure is reduced, so the numbers can be compared against anything
 * else; the record itself keeps what the sensor measured.
 *
 * Hypsometric formula with the station's own temperature rather than the
 * standard atmosphere's fixed 15 °C. A frost or a heatwave moves the column's
 * mean temperature by tens of kelvin, which is a few hPa at this height - the
 * sensor already measures it, so there is no reason to assume it.
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
        // Mean temperature of the imagined air column between the sensor and
        // sea level: the measured value plus half the standard lapse over the
        // height, which is the column's midpoint.
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
