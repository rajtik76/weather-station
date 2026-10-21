<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RainReportSource;
use App\Models\RainReport;
use App\Models\Sensor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RainReport>
 */
class RainReportFactory extends Factory
{
    protected $model = RainReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sensor_id' => Sensor::factory(),
            'source' => RainReportSource::Detector,
            'timestamp' => fake()->dateTimeBetween('-1 month')->getTimestamp(),
            'detected' => true,
            'raining' => null,
            'discord_message_id' => (string) fake()->numerify('1###############'),
        ];
    }
}
