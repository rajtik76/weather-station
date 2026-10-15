<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sensor;
use App\Models\TemperatureCalibration;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\StationSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TemperatureCalibration> */
class TemperatureCalibrationFactory extends Factory
{
    protected $model = TemperatureCalibration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sensor_id' => Sensor::factory(),
            'feature_version' => ShieldFeatures::VERSION,
            'trained_from' => 1_790_712_000,
            'trained_until' => 1_791_576_000,
            'validated_until' => 1_792_008_000,
            'accepted' => true,
            'geometry' => ['latitude' => StationSite::LATITUDE, 'longitude' => StationSite::LONGITUDE, 'wall_azimuth' => 65.0],
            'coefficients' => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
            'limits' => ['max_lux' => 3000.0, 'max_heating' => 12.0],
            'scores' => [],
        ];
    }
}
