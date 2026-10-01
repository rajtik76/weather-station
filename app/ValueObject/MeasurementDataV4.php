<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use UnexpectedValueException;

/**
 * Protocol V4: a V3 window plus optional VEML7700 illuminance.
 */
final readonly class MeasurementDataV4 implements CarriesLight, CarriesNoise
{
    public ProtocolVersion $protocolVersion;

    public function __construct(
        public int $temperature,
        public int $humidity,
        public int $pressure,
        public int $temperatureMin,
        public int $temperatureMax,
        public int $humidityMin,
        public int $humidityMax,
        public int $pressureMin,
        public int $pressureMax,
        public int $samples,
        public ?NoiseWindow $noise = null,
        public ?LightWindow $light = null,
    ) {
        $this->protocolVersion = ProtocolVersion::V4;
    }

    public static function fromArray(array $data): self
    {
        foreach (array_keys(MeasurementDataV2::validationRules()) as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw new UnexpectedValueException("Missing or invalid field [{$field}] for protocol version 4.");
            }
        }

        $noise = null;

        if (isset($data['noise'])) {
            if (! is_array($data['noise'])) {
                throw new UnexpectedValueException('Missing or invalid field [noise] for protocol version 4.');
            }

            $noise = NoiseWindow::fromArray($data['noise']);
        }

        return new self(
            temperature: (int) $data['temperature'],
            humidity: (int) $data['humidity'],
            pressure: (int) $data['pressure'],
            temperatureMin: (int) $data['temperature_min'],
            temperatureMax: (int) $data['temperature_max'],
            humidityMin: (int) $data['humidity_min'],
            humidityMax: (int) $data['humidity_max'],
            pressureMin: (int) $data['pressure_min'],
            pressureMax: (int) $data['pressure_max'],
            samples: (int) $data['samples'],
            noise: $noise,
            light: LightWindow::fromArray($data),
        );
    }

    /**
     * The V3 rules plus the light, optional as a set, complete once any is present. 15 000 000 is 150 klx, above the sensor's top range.
     */
    public static function validationRules(): array
    {
        return [
            ...MeasurementDataV3::validationRules(),
            'illuminance' => ['required_with:measurements.*.illuminance_min,measurements.*.illuminance_max', 'integer', 'min:0', 'max:15000000'],
            'illuminance_min' => ['required_with:measurements.*.illuminance,measurements.*.illuminance_max', 'integer', 'min:0', 'lte:measurements.*.illuminance'],
            'illuminance_max' => ['required_with:measurements.*.illuminance,measurements.*.illuminance_min', 'integer', 'max:15000000', 'gte:measurements.*.illuminance'],
        ];
    }

    public function jsonSerialize(): array
    {
        $data = [
            'temperature' => $this->temperature,
            'humidity' => $this->humidity,
            'pressure' => $this->pressure,
            'temperature_min' => $this->temperatureMin,
            'temperature_max' => $this->temperatureMax,
            'humidity_min' => $this->humidityMin,
            'humidity_max' => $this->humidityMax,
            'pressure_min' => $this->pressureMin,
            'pressure_max' => $this->pressureMax,
            'samples' => $this->samples,
        ];

        if ($this->light instanceof LightWindow) {
            $data = [...$data, ...$this->light->jsonSerialize()];
        }

        if ($this->noise instanceof NoiseWindow) {
            $data['noise'] = $this->noise->jsonSerialize();
        }

        return $data;
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), flags: JSON_THROW_ON_ERROR);
    }
}
