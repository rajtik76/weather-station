<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use UnexpectedValueException;

/**
 * Protocol V2: a ten-minute window per entry. The mean keeps the V1 keys so
 * the dashboard's SQL aggregates both versions with one expression.
 */
final readonly class MeasurementDataV2 implements MeasurementData
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
    ) {
        $this->protocolVersion = ProtocolVersion::V2;
    }

    public static function fromArray(array $data): self
    {
        foreach (array_keys(self::validationRules()) as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw new UnexpectedValueException("Missing or invalid field [{$field}] for protocol version 2.");
            }
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
        );
    }

    /**
     * A minimum above the mean is a firmware fault, not a reading. The
     * validator replaces the wildcard with the entry's own index.
     */
    public static function validationRules(): array
    {
        return [
            'temperature' => ['required', 'integer', 'min:-4000', 'max:8500'],
            'humidity' => ['required', 'integer', 'min:0', 'max:10000'],
            'pressure' => ['required', 'integer', 'min:30000', 'max:110000'],
            'temperature_min' => ['required', 'integer', 'min:-4000', 'lte:measurements.*.temperature'],
            'temperature_max' => ['required', 'integer', 'max:8500', 'gte:measurements.*.temperature'],
            'humidity_min' => ['required', 'integer', 'min:0', 'lte:measurements.*.humidity'],
            'humidity_max' => ['required', 'integer', 'max:10000', 'gte:measurements.*.humidity'],
            'pressure_min' => ['required', 'integer', 'min:30000', 'lte:measurements.*.pressure'],
            'pressure_max' => ['required', 'integer', 'max:110000', 'gte:measurements.*.pressure'],
            'samples' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }

    public function jsonSerialize(): array
    {
        return [
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
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), flags: JSON_THROW_ON_ERROR);
    }
}
