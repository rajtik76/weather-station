<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use UnexpectedValueException;

final readonly class MeasurementDataV1 implements MeasurementData
{
    public ProtocolVersion $protocolVersion;

    public function __construct(
        public int $temperature,
        public int $humidity,
        public int $pressure,
    ) {
        $this->protocolVersion = ProtocolVersion::V1;
    }

    public static function fromArray(array $data): self
    {
        foreach (['temperature', 'humidity', 'pressure'] as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw new UnexpectedValueException("Missing or invalid field [{$field}] for protocol version 1.");
            }
        }

        return new self(
            temperature: (int) $data['temperature'],
            humidity: (int) $data['humidity'],
            pressure: (int) $data['pressure'],
        );
    }

    public static function validationRules(): array
    {
        return [
            'temperature' => ['required', 'integer', 'min:-4000', 'max:8500'],
            'humidity' => ['required', 'integer', 'min:0', 'max:10000'],
            'pressure' => ['required', 'integer', 'min:30000', 'max:110000'],
        ];
    }

    public function jsonSerialize(): array
    {
        return [
            'temperature' => $this->temperature,
            'humidity' => $this->humidity,
            'pressure' => $this->pressure,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), flags: JSON_THROW_ON_ERROR);
    }
}
