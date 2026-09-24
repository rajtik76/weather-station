<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * An entry in the units the dashboard prints: °C and % from the protocol's
 * hundredths, pressure reduced to sea level at the station's height. The
 * stored reading stays as sent, so a corrected height never means rewriting
 * stored rows.
 */
final readonly class Readout
{
    /** Station height for the sea-level reduction, Plzeň-Slovany. */
    public const float ALTITUDE_METRES = 345.0;

    private function __construct(private MeasurementData $data) {}

    public static function of(MeasurementData $data): self
    {
        return new self($data);
    }

    /** Two decimals: the protocol's own hundredths. */
    public static function hundredths(float|int|string $value): float
    {
        return round((float) $value / 100, 2);
    }

    public function temperature(): float
    {
        return self::hundredths($this->data->temperature);
    }

    public function humidity(): float
    {
        return self::hundredths($this->data->humidity);
    }

    public function pressure(): float
    {
        return $this->seaLevel($this->data->pressure);
    }

    /**
     * Another pressure reduced with this entry's temperature, for an extreme:
     * the sample that read it kept no temperature of its own. Two decimals:
     * whole pascals, the sensor's resolution. Tenths drew the pressure line
     * as a staircase.
     */
    public function seaLevel(int $pascals): float
    {
        $reading = new MeasurementDataV1(
            temperature: $this->data->temperature,
            humidity: $this->data->humidity,
            pressure: $pascals,
        );

        return SeaLevelPressure::reduce($reading, self::ALTITUDE_METRES)->hectopascals(2);
    }

    /** Null where there is none (see DewPoint::of); the chart draws a gap. */
    public function dewPoint(): ?float
    {
        return DewPoint::of($this->data)?->celsius(2);
    }

    /**
     * Every channel with its extremes, keyed as the day readouts read them.
     *
     * @return array{t: float, h: float, p: float, tMin: float, tMax: float, hMin: float, hMax: float, pMin: float, pMax: float}
     */
    public function toArray(): array
    {
        return [
            't' => $this->temperature(),
            'h' => $this->humidity(),
            'p' => $this->pressure(),
            'tMin' => self::hundredths($this->data->temperatureMin),
            'tMax' => self::hundredths($this->data->temperatureMax),
            'hMin' => self::hundredths($this->data->humidityMin),
            'hMax' => self::hundredths($this->data->humidityMax),
            'pMin' => $this->seaLevel($this->data->pressureMin),
            'pMax' => $this->seaLevel($this->data->pressureMax),
        ];
    }
}
