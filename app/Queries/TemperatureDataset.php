<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\ValueObject\ChmuTemperatures;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\ShieldSample;

final readonly class TemperatureDataset
{
    /** @return list<ShieldSample> */
    public function samples(int $sensorId, int $from, int $until, ShieldFeatures $features): array
    {
        $measurements = Measurement::query()
            ->where('sensor_id', $sensorId)
            ->where('timestamp', '>=', $from)
            ->where('timestamp', '<', $until)
            ->orderBy('timestamp')
            ->lazy(500);

        return $features->samples($measurements);
    }

    /**
     * @param  list<ShieldSample>  $samples
     * @return list<array{sample: ShieldSample, reference: float, reference_at: int}>
     */
    public function pair(array $samples): array
    {
        if ($samples === []) {
            return [];
        }

        $references = ReferenceTemperature::query()
            ->where('station', ChmuTemperatures::STATION)
            ->whereBetween('timestamp', [$samples[0]->timestamp - 90, $samples[array_key_last($samples)]->timestamp + 90])
            ->whereNotNull('temperature')
            ->pluck('temperature', 'timestamp');

        $pairs = [];

        foreach ($samples as $sample) {
            $slot = (int) round($sample->timestamp / 600) * 600;

            if (abs($sample->timestamp - $slot) > 90 || ! isset($references[$slot])) {
                continue;
            }

            $pairs[] = ['sample' => $sample, 'reference' => (float) $references[$slot], 'reference_at' => $slot];
        }

        return $pairs;
    }
}
