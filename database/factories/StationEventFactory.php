<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StationEvent>
 */
class StationEventFactory extends Factory
{
    protected $model = StationEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => fake()->dateTimeBetween('-1 year'),
            'title' => fake()->sentence(3),
            'color' => fake()->optional()->hexColor(),
        ];
    }
}
