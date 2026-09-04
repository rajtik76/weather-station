<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\MeasurementData;
use App\ValueObject\MeasurementDataV1;

enum ProtocolVersion: int
{
    // Cases are append-only. Dropping one makes every historical row of that version unreadable.
    case V1 = 1; // first version of protocol, all fields are integers: {"temperature": -4000-8500 (0.01℃), "humidity": 0-10000 (0.01%), "pressure": 30000-110000 (Pa) }

    /**
     * @return class-string<MeasurementData>
     */
    public function dataClass(): string
    {
        return match ($this) {
            self::V1 => MeasurementDataV1::class,
        };
    }

    /**
     * Rules for a single measurement item. Timestamp is shared by all versions and lives in the request.
     *
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array
    {
        return $this->dataClass()::validationRules();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hydrate(array $data): MeasurementData
    {
        return $this->dataClass()::fromArray($data);
    }
}
