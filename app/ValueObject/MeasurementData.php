<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use JsonSerializable;
use Stringable;

/**
 * One stored entry in protocol units. Every version exposes a value, its
 * extremes and a sample count per channel; a single V1 reading is its own
 * min and max with one sample, so versions aggregate alike.
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
     * @return array<string, int>
     */
    public function jsonSerialize(): array;
}
