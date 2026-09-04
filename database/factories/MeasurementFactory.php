<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Measurement>
 */
class MeasurementFactory extends Factory
{
    protected $model = Measurement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = CarbonImmutable::parse(fake()->dateTimeBetween('-1 year'));

        return [
            'sensor_name' => fake()->name(),
            'timestamp' => $date->timestamp,
            'protocol_version' => ProtocolVersion::V1,
            // The model has no data setter, the column takes the encoded blob.
            'data' => (string) new MeasurementDataV1(
                temperature: fake()->numberBetween(-4000, 8500),
                humidity: fake()->numberBetween(0, 10000),
                pressure: fake()->numberBetween(30000, 110000),
            ),
            'created_at' => $date,
            'updated_at' => $date,
        ];
    }
}
