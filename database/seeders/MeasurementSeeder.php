<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use Carbon\CarbonPeriodImmutable;
use Illuminate\Database\Seeder;

class MeasurementSeeder extends Seeder
{
    public function run(): void
    {
        $start = now()->subMonth()->toImmutable();
        $end = now()->toImmutable();
        $interval = new CarbonPeriodImmutable($start, '10 minutes', $end);

        foreach ($interval as $item) {
            Measurement::factory()
                ->create([
                    'sensor_name' => 'test-sensor-001',
                    'protocol_version' => ProtocolVersion::V1,
                    'timestamp' => $item->getTimestamp(),
                ]);
        }
    }
}
