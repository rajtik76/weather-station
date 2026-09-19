<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use Illuminate\Database\Seeder;

class MeasurementSeeder extends Seeder
{
    private const int STEP_SECONDS = 600;

    private const int SAMPLES_PER_WINDOW = 20;

    /**
     * Two stations on the same month: the second is warmer, drier, starts
     * mid-month and is V2 throughout, the first switches to V2 on
     * `v2FromDay` as the real one did.
     *
     * @var list<array{name: string, description: string, warmerBy: float, drierBy: float, skipDays: int, v2FromDay: int, sunSpikes: bool}>
     */
    private const array SENSORS = [
        [
            'name' => 'sensor-001',
            'description' => 'North wall under the eaves, in a radiation shield.',
            'warmerBy' => 0.0,
            'drierBy' => 0.0,
            'skipDays' => 0,
            'v2FromDay' => 21,
            'sunSpikes' => false,
        ],
        [
            'name' => 'sensor-002',
            'description' => 'South balcony, unshielded - runs warm in the afternoon sun.',
            'warmerBy' => 1.8,
            'drierBy' => 6.0,
            'skipDays' => 12,
            'v2FromDay' => 12,
            'sunSpikes' => true,
        ],
    ];

    /**
     * Real hourly observations for Plzen-Slovany (Open-Meteo, 345 m), 744
     * points, interpolated to ten minutes and jittered. Real data because a
     * model gets pressure wrong at once: 1013 hPa is sea level, a BME280 at
     * 345 m reads ~977.
     */
    public function run(): void
    {
        $observations = $this->observations();
        $hours = count($observations['temperature_c']);
        $perHour = 3600 / self::STEP_SECONDS;
        $count = $hours * $perHour;

        // Fixed seed, so a reseed returns the same month.
        mt_srand(1898);

        // Last closed slot; a V2 stamp near the end of an open one would lie in the future.
        $end = (int) (floor(now()->getTimestamp() / self::STEP_SECONDS) - 1) * self::STEP_SECONDS;
        $start = $end - ($count - 1) * self::STEP_SECONDS;

        foreach (self::SENSORS as $station) {
            // Model events are off in seeders; the slug is passed by hand.
            $sensor = Sensor::query()->firstOrCreate(
                ['name' => $station['name']],
                ['slug' => Sensor::uniqueSlug($station['name']), 'description' => $station['description']],
            );

            $rows = [];

            for ($i = $station['skipDays'] * 24 * $perHour; $i < $count; $i++) {
                $position = $i / $perHour;
                $hour = (int) floor($position);
                $into = $position - $hour;

                $temperature = $this->between($observations['temperature_c'], $hour, $into) + $station['warmerBy'] + mt_rand(-4, 4) / 100;
                $humidity = $this->between($observations['humidity_pct'], $hour, $into) - $station['drierBy'] + mt_rand(-20, 20) / 100;
                $pressure = $this->between($observations['pressure_hpa'], $hour, $into) + mt_rand(-3, 3) / 100;

                // Protocol units.
                $t = (int) round($temperature * 100);
                $h = (int) round(max(0.0, min(100.0, $humidity)) * 100);
                $p = (int) round($pressure * 100);

                $slot = $start + $i * self::STEP_SECONDS;
                $v2 = $i >= $station['v2FromDay'] * 24 * $perHour;

                $rows[] = [
                    'sensor_id' => $sensor->id,
                    'protocol_version' => ($v2 ? ProtocolVersion::V2 : ProtocolVersion::V1)->value,
                    // Stamped by the last reading, half a minute short of the slot's end.
                    'timestamp' => $v2 ? $slot + self::STEP_SECONDS - 30 : $slot,
                    'data' => (string) ($v2
                        ? $this->window($t, $h, $p, $station['sunSpikes'] && $this->inAfternoonSun($slot))
                        : new MeasurementDataV1(temperature: $t, humidity: $h, pressure: $p)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Measurement::insert($chunk);
            }

            // A report for the last upload, so the station block has something to show.
            StationReport::query()->create([
                'sensor_id' => $sensor->id,
                'data' => [
                    'firmware' => '2.1.0',
                    'reset_reason' => 'power on',
                    'uptime' => ($count - $station['skipDays'] * 24 * $perHour) * self::STEP_SECONDS,
                    'heap_free' => 186_000 - mt_rand(0, 8_000),
                    'heap_min' => 151_000 - mt_rand(0, 4_000),
                    'ssid' => 'home',
                    'ip' => '192.168.0.'.(40 + $sensor->id),
                    'rssi' => -60 - mt_rand(0, 15),
                    'wifi_network' => 0,
                    'wifi_switches' => 0,
                    'buffered' => 0,
                    'upload_failures' => 0,
                ],
            ]);
        }
    }

    /**
     * A V2 window around a mean. Under the sun the maximum runs away from
     * the mean while the minimum stays put, and the humidity minimum drops.
     */
    private function window(int $t, int $h, int $p, bool $inSun): MeasurementDataV2
    {
        $tSpike = $inSun ? mt_rand(80, 320) : 0;
        $hDip = $inSun ? mt_rand(100, 400) : 0;

        return new MeasurementDataV2(
            temperature: $t,
            humidity: $h,
            pressure: $p,
            temperatureMin: $t - mt_rand(5, 35),
            temperatureMax: $t + mt_rand(5, 35) + $tSpike,
            humidityMin: max(0, $h - mt_rand(20, 120) - $hDip),
            humidityMax: min(10000, $h + mt_rand(20, 120)),
            pressureMin: $p - mt_rand(2, 10),
            pressureMax: $p + mt_rand(2, 10),
            samples: mt_rand(0, 9) === 0 ? self::SAMPLES_PER_WINDOW - 1 : self::SAMPLES_PER_WINDOW,
        );
    }

    /** Hours of direct sun on the balcony. */
    private function inAfternoonSun(int $slot): bool
    {
        $hour = (int) now()->setTimestamp($slot)->setTimezone('Europe/Prague')->format('G');

        return $hour >= 12 && $hour < 17;
    }

    /**
     * @param  list<float>  $series
     */
    private function between(array $series, int $hour, float $into): float
    {
        $from = $series[$hour];
        $to = $series[$hour + 1] ?? $from;

        return $from + ($to - $from) * $into;
    }

    /**
     * @return array{temperature_c: list<float>, humidity_pct: list<float>, pressure_hpa: list<float>}
     */
    private function observations(): array
    {
        $path = __DIR__.'/data/pilsen-hourly.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return [
            'temperature_c' => $decoded['temperature_c'],
            'humidity_pct' => $decoded['humidity_pct'],
            'pressure_hpa' => $decoded['pressure_hpa'],
        ];
    }
}
