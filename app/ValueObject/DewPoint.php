<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Magnus formula with the Sonntag 1990 constants; good to a few hundredths
 * of a degree between -45 and 60 °C, well inside the sensor's own error.
 */
final readonly class DewPoint
{
    /** Magnus coefficient, dimensionless. */
    private const float MAGNUS_A = 17.62;

    /** Magnus coefficient, °C. */
    private const float MAGNUS_B = 243.12;

    private function __construct(public float $celsius) {}

    /**
     * Null at 0 %: log(0) is -inf, and 0 % is what a BME280 reports when its
     * humidity path has failed. A gap says that; a point at -70 °C would
     * flatten the whole chart.
     */
    public static function of(MeasurementData $data): ?self
    {
        if ($data->humidity <= 0) {
            return null;
        }

        $temperature = $data->temperature / 100;
        $humidity = $data->humidity / 10000;

        $gamma = log($humidity) + self::MAGNUS_A * $temperature / (self::MAGNUS_B + $temperature);

        return new self(self::MAGNUS_B * $gamma / (self::MAGNUS_A - $gamma));
    }

    public function celsius(int $decimals = 1): float
    {
        return round($this->celsius, $decimals);
    }
}
