<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * The dew point of a station reading.
 *
 * The temperature the air would have to cool to before its moisture condensed,
 * derived from the measured temperature and relative humidity. It is a
 * temperature and never exceeds the measured one - the two meet at 100 %.
 *
 * Magnus formula with the Sonntag 1990 constants, which hold to a few
 * hundredths of a degree between -45 and 60 °C; the sensor's own accuracy is
 * an order of magnitude coarser than that.
 */
final readonly class DewPoint
{
    /** Magnus coefficient, dimensionless. */
    private const float MAGNUS_A = 17.62;

    /** Magnus coefficient, °C. */
    private const float MAGNUS_B = 243.12;

    private function __construct(public float $celsius) {}

    /**
     * Null for a reading of 0 %: the logarithm of that is minus infinity, and
     * the nearest finite answer, some -70 °C, is not a dew point either - it
     * is what a BME280 reports when its humidity path has failed. A gap in
     * the line says that; a point at -70 °C on the temperature's own axis
     * would flatten every real reading in the window instead.
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
