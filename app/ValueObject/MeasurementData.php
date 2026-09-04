<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enums\ProtocolVersion;

interface MeasurementData extends \JsonSerializable, \Stringable
{
    public int $temperature { get; }

    public int $humidity { get; }

    public int $pressure { get; }

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
