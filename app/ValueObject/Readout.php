<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * An entry in dashboard units: °C and %, pressure reduced to sea level at the station's height. The stored reading stays as sent.
 *
 * @phpstan-type DayRow array{t: float, h: float, p: float, tMin: float, tMax: float, hMin: float, hMax: float, pMin: float, pMax: float, n: ?float, l: ?float, lMin: ?float, lMax: ?float}
 */
final readonly class Readout
{
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
     * Reduced with this entry's temperature, for an extreme (the sample kept none). Two decimals: tenths drew a staircase.
     */
    public function seaLevel(int $pascals): float
    {
        $reading = new MeasurementDataV1(
            temperature: $this->data->temperature,
            humidity: $this->data->humidity,
            pressure: $pascals,
        );

        return SeaLevelPressure::reduce($reading, StationSite::ALTITUDE_METRES)->hectopascals(2);
    }

    /** Null where there is none (see DewPoint::of). */
    public function dewPoint(): ?float
    {
        return DewPoint::of($this->data)?->celsius(2);
    }

    /** LAeq in dB, tenths. Null without microphone data. */
    public function noise(): ?float
    {
        if (! $this->data instanceof CarriesNoise || ! $this->data->noise instanceof NoiseWindow) {
            return null;
        }

        return round($this->data->noise->laeq / 100, 1);
    }

    /** Lux behind the shield, from hundredths. Null without light. */
    public function light(): ?float
    {
        return $this->lightWindow() instanceof LightWindow ? self::hundredths($this->lightWindow()->illuminance) : null;
    }

    private function lightWindow(): ?LightWindow
    {
        return $this->data instanceof CarriesLight ? $this->data->light : null;
    }

    /**
     * Every channel with its extremes.
     *
     * @return DayRow
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
            'n' => $this->noise(),
            'l' => $this->light(),
            'lMin' => $this->lightWindow() instanceof LightWindow ? self::hundredths($this->lightWindow()->illuminanceMin) : null,
            'lMax' => $this->lightWindow() instanceof LightWindow ? self::hundredths($this->lightWindow()->illuminanceMax) : null,
        ];
    }
}
