<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use JsonSerializable;
use Stringable;

/**
 * One stored entry in protocol units: a value, extremes and sample count per channel, in every version.
 */
interface MeasurementData extends JsonSerializable, Stringable
{
    public int $temperature { get; }

    public int $humidity { get; }

    public int $pressure { get; }

    public int $temperatureMin { get; }

    public int $temperatureMax { get; }

    public int $humidityMin { get; }

    public int $humidityMax { get; }

    public int $pressureMin { get; }

    public int $pressureMax { get; }

    public int $samples { get; }

    public ProtocolVersion $protocolVersion { get; }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self;

    /**
     * @return array<string, array<int, string>>
     */
    public static function validationRules(): array;

    /**
     * Widened for V3's nested "noise" object.
     *
     * @return array<string, int|array<string, int|list<int>>>
     */
    public function jsonSerialize(): array;
}
