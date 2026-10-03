<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Forecast;
use App\Models\Sensor;
use App\Queries\CachedForecastAccuracy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LightForecastSeeder extends Seeder
{
    private const string VERSION = 'demo-light-v1';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach (Sensor::query()->get() as $sensor) {
            /** @var list<object{timestamp: int, illuminance: int}> $readings */
            $readings = DB::table('measurements')
                ->where('sensor_id', $sensor->id)
                ->whereRaw("data->>'illuminance' IS NOT NULL")
                ->orderBy('timestamp')
                ->orderBy('id')
                ->select('timestamp')
                ->selectRaw("(data->>'illuminance')::bigint AS illuminance")
                ->get()
                ->all();

            $illumination = [];

            foreach ($readings as $reading) {
                $slot = intdiv($reading->timestamp, Forecast::INTERVAL_SECONDS) * Forecast::INTERVAL_SECONDS;
                $illumination[$slot] ??= $reading->illuminance / 100;
            }

            if ($illumination === []) {
                continue;
            }

            Forecast::query()
                ->where('sensor_id', $sensor->id)
                ->where('issued_at', '>=', array_key_first($illumination))
                ->each(function (Forecast $forecast) use ($illumination): void {
                    $lux = $illumination[$forecast->issued_at] ?? null;

                    if ($lux === null) {
                        return;
                    }

                    $data = $forecast->data;
                    $weight = $lux / ($lux + 1000);

                    foreach ($data as &$horizon) {
                        if (! isset($horizon['base']) || isset($horizon['experiment'])) {
                            continue;
                        }

                        $temperature = [];

                        foreach (['low', 'mid', 'high'] as $quantile) {
                            $base = $horizon['base']['temperature'][$quantile];
                            $shown = $horizon['temperature'][$quantile];
                            $temperature[$quantile] = round($base + $weight * ($shown - $base), 2);
                        }

                        $horizon['experiment'] = ['version' => self::VERSION, 'temperature' => $temperature, 'synthetic' => true];
                    }

                    unset($horizon);

                    if ($data !== $forecast->data) {
                        $forecast->update(['data' => $data]);
                    }
                });

            new CachedForecastAccuracy($sensor->id)->forget();
        }
    }
}
