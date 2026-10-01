<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\LightWindow;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;
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
            'sensor_id' => Sensor::factory(),
            'timestamp' => $date->timestamp,
            'protocol_version' => ProtocolVersion::V1,
            // No data setter on the model; the column takes the encoded blob.
            'data' => (string) new MeasurementDataV1(
                temperature: fake()->numberBetween(-4000, 8500),
                humidity: fake()->numberBetween(0, 10000),
                pressure: fake()->numberBetween(30000, 110000),
            ),
            'created_at' => $date,
            'updated_at' => $date,
        ];
    }

    public function v2(): static
    {
        return $this->state(function (): array {
            $temperature = fake()->numberBetween(-3800, 8300);
            $humidity = fake()->numberBetween(200, 9800);
            $pressure = fake()->numberBetween(30200, 109800);

            return [
                'protocol_version' => ProtocolVersion::V2,
                'data' => (string) new MeasurementDataV2(
                    temperature: $temperature,
                    humidity: $humidity,
                    pressure: $pressure,
                    temperatureMin: $temperature - fake()->numberBetween(0, 200),
                    temperatureMax: $temperature + fake()->numberBetween(0, 200),
                    humidityMin: $humidity - fake()->numberBetween(0, 200),
                    humidityMax: $humidity + fake()->numberBetween(0, 200),
                    pressureMin: $pressure - fake()->numberBetween(0, 200),
                    pressureMax: $pressure + fake()->numberBetween(0, 200),
                    samples: fake()->numberBetween(1, 20),
                ),
            ];
        });
    }

    public function v3(): static
    {
        return $this->state(function (): array {
            $temperature = fake()->numberBetween(-3800, 8300);
            $humidity = fake()->numberBetween(200, 9800);
            $pressure = fake()->numberBetween(30200, 109800);

            return [
                'protocol_version' => ProtocolVersion::V3,
                'data' => (string) new MeasurementDataV3(
                    temperature: $temperature,
                    humidity: $humidity,
                    pressure: $pressure,
                    temperatureMin: $temperature - fake()->numberBetween(0, 200),
                    temperatureMax: $temperature + fake()->numberBetween(0, 200),
                    humidityMin: $humidity - fake()->numberBetween(0, 200),
                    humidityMax: $humidity + fake()->numberBetween(0, 200),
                    pressureMin: $pressure - fake()->numberBetween(0, 200),
                    pressureMax: $pressure + fake()->numberBetween(0, 200),
                    samples: fake()->numberBetween(1, 20),
                ),
            ];
        });
    }

    public function v4(): static
    {
        return $this->state(function (): array {
            $temperature = fake()->numberBetween(-3800, 8300);
            $humidity = fake()->numberBetween(200, 9800);
            $pressure = fake()->numberBetween(30200, 109800);
            $illuminance = fake()->numberBetween(0, 2_000_000);

            return [
                'protocol_version' => ProtocolVersion::V4,
                'data' => (string) new MeasurementDataV4(
                    temperature: $temperature,
                    humidity: $humidity,
                    pressure: $pressure,
                    temperatureMin: $temperature - fake()->numberBetween(0, 200),
                    temperatureMax: $temperature + fake()->numberBetween(0, 200),
                    humidityMin: $humidity - fake()->numberBetween(0, 200),
                    humidityMax: $humidity + fake()->numberBetween(0, 200),
                    pressureMin: $pressure - fake()->numberBetween(0, 200),
                    pressureMax: $pressure + fake()->numberBetween(0, 200),
                    samples: fake()->numberBetween(1, 20),
                    light: new LightWindow(
                        illuminance: $illuminance,
                        illuminanceMin: intdiv($illuminance, 2),
                        illuminanceMax: $illuminance * 2,
                    ),
                ),
            ];
        });
    }
}
