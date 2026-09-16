<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;
use JsonSerializable;
use Stringable;

/**
 * One stored entry, in the protocol's fixed point units.
 *
 * Every version reports a representative value per channel - the reading
 * itself on V1, the mean over the window on V2 - plus the extremes and the
 * sample count behind it. A single reading is its own minimum and maximum
 * with one sample, so the dashboard can aggregate any mix of versions the
 * same way.
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

    /** How many sensor readings the entry stands for. */
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
