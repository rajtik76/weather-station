<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Forecast;
use App\Models\Sensor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Forecast>
 */
class ForecastFactory extends Factory
{
    protected $model = Forecast::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $temperature = fake()->randomFloat(2, -10, 30);
        $humidity = fake()->randomFloat(2, 30, 95);
        $pressure = fake()->randomFloat(2, 960, 1000);

        return [
            'sensor_id' => Sensor::factory(),
            'issued_at' => fake()->dateTimeBetween('-1 month')->getTimestamp(),
            'model' => '2026-09-24T08:40:43.136429+00:00',
            'corrected' => true,
            // The range widens with the horizon, as the service's does.
            'data' => array_map(fn (int $hours): array => [
                'hours' => $hours,
                'temperature' => ['low' => $temperature - $hours * 0.5, 'mid' => $temperature, 'high' => $temperature + $hours * 0.5],
                'humidity' => ['low' => $humidity - $hours * 2, 'mid' => $humidity, 'high' => $humidity + $hours * 2],
                'pressure' => ['low' => $pressure - $hours * 0.3, 'mid' => $pressure, 'high' => $pressure + $hours * 0.3],
                'rain_probability' => fake()->randomFloat(3, 0, 1),
            ], range(1, 6)),
        ];
    }
}
