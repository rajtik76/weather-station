<?php

declare(strict_types=1);

namespace App\ValueObject;

use UnexpectedValueException;

/**
 * Protocol V4's optional illuminance: mean and extremes in hundredths of a lux, flat keys, all three or none. Measured behind the radiation shield, not open sky.
 */
final readonly class LightWindow
{
    public const array FIELDS = ['illuminance', 'illuminance_min', 'illuminance_max'];

    public function __construct(
        public int $illuminance,
        public int $illuminanceMin,
        public int $illuminanceMax,
    ) {}

    /**
     * Null when none of the three keys is present.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $present = array_filter(self::FIELDS, fn (string $field): bool => array_key_exists($field, $data));

        if ($present === []) {
            return null;
        }

        foreach (self::FIELDS as $field) {
            if (! isset($data[$field]) || ! is_numeric($data[$field])) {
                throw new UnexpectedValueException("Missing or invalid field [{$field}] for protocol version 4.");
            }
        }

        return new self(
            illuminance: (int) $data['illuminance'],
            illuminanceMin: (int) $data['illuminance_min'],
            illuminanceMax: (int) $data['illuminance_max'],
        );
    }

    /**
     * @return array{illuminance: int, illuminance_min: int, illuminance_max: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'illuminance' => $this->illuminance,
            'illuminance_min' => $this->illuminanceMin,
            'illuminance_max' => $this->illuminanceMax,
        ];
    }
}
