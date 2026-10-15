<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReferenceTemperature;
use App\ValueObject\ChmuTemperatures;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReferenceTemperature> */
class ReferenceTemperatureFactory extends Factory
{
    protected $model = ReferenceTemperature::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'station' => ChmuTemperatures::STATION,
            'timestamp' => fake()->unique()->numberBetween(1_700_000_000, 1_800_000_000),
            'temperature' => 12.0,
            'quality' => 5,
            'source' => 'mikulka.json',
        ];
    }
}
