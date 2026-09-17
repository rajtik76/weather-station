<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sensor;
use App\Models\StationReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StationReport>
 */
class StationReportFactory extends Factory
{
    protected $model = StationReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $heapMin = fake()->numberBetween(120_000, 180_000);

        return [
            'sensor_id' => Sensor::factory(),
            'data' => [
                'firmware' => '2.1.0',
                'reset_reason' => fake()->randomElement(['power on', 'software restart', 'task watchdog']),
                'uptime' => fake()->numberBetween(60, 864_000),
                'heap_free' => fake()->numberBetween($heapMin, 220_000),
                'heap_min' => $heapMin,
                'ssid' => fake()->word(),
                'ip' => fake()->localIpv4(),
                'rssi' => fake()->numberBetween(-85, -50),
                'wifi_network' => 0,
                'wifi_switches' => 0,
                'buffered' => fake()->numberBetween(0, 3),
                'upload_failures' => 0,
            ],
        ];
    }
}
