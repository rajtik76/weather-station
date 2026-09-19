<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\MeasurementData;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;

enum ProtocolVersion: int
{
    // Cases are append-only. Dropping one makes every historical row of that version unreadable.
    case V1 = 1; // first version of protocol, all fields are integers: {"temperature": -4000-8500 (0.01℃), "humidity": 0-10000 (0.01%), "pressure": 30000-110000 (Pa) }
    case V2 = 2; // ten-minute window per entry: the V1 keys carry the mean, plus "<channel>_min", "<channel>_max" in the same units and "samples" 1-65535

    /**
     * @return class-string<MeasurementData>
     */
    public function dataClass(): string
    {
        return match ($this) {
            self::V1 => MeasurementDataV1::class,
            self::V2 => MeasurementDataV2::class,
        };
    }

    /**
     * Rules for one measurement; the timestamp is shared and lives in the request.
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
