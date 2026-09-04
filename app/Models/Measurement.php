<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProtocolVersion;
use App\ValueObject\MeasurementData;
use Database\Factories\MeasurementFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Measurement extends Model
{
    /** @use HasFactory<MeasurementFactory> */
    use HasFactory;

    /**
     * @return Attribute<MeasurementData, never>
     */
    protected function data(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes): MeasurementData {
                if ($value === null) {
                    throw new \UnexpectedValueException('Measurement data is not loaded.');
                }

                $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($decoded)) {
                    throw new \UnexpectedValueException('Malformed measurement data.');
                }

                // The version lives in its own column, never inside the blob.
                return ProtocolVersion::from((int) $attributes['protocol_version'])->hydrate($decoded);
            },
        )->shouldCache();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol_version' => ProtocolVersion::class,
            'timestamp' => 'integer',
        ];
    }
}
