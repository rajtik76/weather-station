<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObject\MeasurementData;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;

enum ProtocolVersion: int
{
    // Append-only: dropping a case makes its historical rows unreadable.
    case V1 = 1; // integers: temperature 0.01 °C, humidity 0.01 %, pressure Pa
    case V2 = 2; // ten-minute window: V1 keys are the mean, plus "<channel>_min"/"_max" and "samples"
    case V3 = 3; // V2 plus optional "noise": levels in 0.01 dB(A), 26 unweighted third-octave "bands" in 0.01 dB
    case V4 = 4; // V3 plus optional "illuminance" and its _min/_max (0.01 lx, behind the shield), all three or none

    /**
     * @return class-string<MeasurementData>
     */
    public function dataClass(): string
    {
        return match ($this) {
            self::V1 => MeasurementDataV1::class,
            self::V2 => MeasurementDataV2::class,
            self::V3 => MeasurementDataV3::class,
            self::V4 => MeasurementDataV4::class,
        };
    }

    /**
     * Rules for one measurement; the shared timestamp lives in the request.
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
