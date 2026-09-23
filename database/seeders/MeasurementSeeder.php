<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\NoiseWindow;
use Illuminate\Database\Seeder;

class MeasurementSeeder extends Seeder
{
    private const int STEP_SECONDS = 600;

    private const int SAMPLES_PER_WINDOW = 20;

    /**
     * Two stations on the same month: the second is warmer, drier, starts
     * mid-month and is V2 throughout, the first switches to V2 on
     * `v2FromDay` and to V3 with noise on `v3FromDay` as the real one did.
     *
     * @var list<array{name: string, description: string, warmerBy: float, drierBy: float, skipDays: int, v2FromDay: int, v3FromDay: ?int, sunSpikes: bool, firmware: string, board: ?string}>
     */
    private const array SENSORS = [
        [
            'name' => 'sensor-001',
            'description' => 'North wall under the eaves, in a radiation shield.',
            'warmerBy' => 0.0,
            'drierBy' => 0.0,
            'skipDays' => 0,
            'v2FromDay' => 21,
            'v3FromDay' => 28,
            'sunSpikes' => false,
            'firmware' => '3.0.2',
            'board' => 'ESP32_DEV',
        ],
        [
            'name' => 'sensor-002',
            'description' => 'South balcony, unshielded - runs warm in the afternoon sun.',
            'warmerBy' => 1.8,
            'drierBy' => 6.0,
            'skipDays' => 12,
            'v2FromDay' => 12,
            'v3FromDay' => null,
            'sunSpikes' => true,
            'firmware' => '2.1.0',
            'board' => null,
        ],
    ];

    /**
     * LAeq per local hour in dB: a quiet night, the morning and afternoon
     * rush on a street a block away, and the evening winding down.
     */
    private const array NOISE_BY_HOUR = [42, 41, 40, 40, 41, 44, 50, 56, 58, 57, 56, 56, 57, 56, 56, 57, 58, 59, 57, 54, 51, 48, 46, 44];

    /**
     * Each third-octave band against the LAeq, in 0.01 dB, 25 Hz to 8 kHz:
     * the mean of the real station's first V3 windows. Unweighted bands, so
     * the low end sits above the A-weighted total.
     */
    private const array BAND_OFFSETS = [935, 775, 556, 718, 315, -173, -304, -655, -1038, -980, -1169, -1245, -1157, -1053, -1187, -1013, -814, -709, -810, -1287, -1793, -2166, -2428, -2582, -2632, -3279];

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
                $version = match (true) {
                    $station['v3FromDay'] !== null && $i >= $station['v3FromDay'] * 24 * $perHour => ProtocolVersion::V3,
                    $i >= $station['v2FromDay'] * 24 * $perHour => ProtocolVersion::V2,
                    default => ProtocolVersion::V1,
                };
                $window = fn (): MeasurementDataV2 => $this->window($t, $h, $p, $station['sunSpikes'] && $this->inAfternoonSun($slot));

                $rows[] = [
                    'sensor_id' => $sensor->id,
                    'protocol_version' => $version->value,
                    // Stamped by the last reading, half a minute short of the slot's end.
                    'timestamp' => $version === ProtocolVersion::V1 ? $slot : $slot + self::STEP_SECONDS - 30,
                    'data' => (string) match ($version) {
                        ProtocolVersion::V1 => new MeasurementDataV1(temperature: $t, humidity: $h, pressure: $p),
                        ProtocolVersion::V2 => $window(),
                        ProtocolVersion::V3 => $this->withNoise($window(), $this->noise($slot)),
                    },
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
                'data' => array_filter([
                    'firmware' => $station['firmware'],
                    'board' => $station['board'],
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
                ], fn (int|string|null $value): bool => $value !== null),
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

    /**
     * A V3 window's noise around the hour's level. One window in twelve
     * holds a loud event - a truck, a siren - that lifts the mean a little
     * and the maximum a lot. A window cut short by its upload heard a few
     * seconds less than ten minutes.
     */
    private function noise(int $slot): NoiseWindow
    {
        $isLoud = mt_rand(0, 11) === 0;
        $laeq = self::NOISE_BY_HOUR[$this->localHour($slot)] * 100 + mt_rand(-150, 150) + ($isLoud ? mt_rand(200, 600) : 0);

        return new NoiseWindow(
            seconds: mt_rand(0, 3) === 0 ? mt_rand(570, 599) : self::STEP_SECONDS,
            laeq: $laeq,
            lamax: $laeq + mt_rand(600, 1400) + ($isLoud ? mt_rand(1000, 2500) : 0),
            la10: $laeq + mt_rand(150, 350),
            la90: $laeq - mt_rand(450, 900),
            bands: array_map(
                fn (int $offset): int => max(0, $laeq + $offset + mt_rand(-150, 150)),
                self::BAND_OFFSETS,
            ),
        );
    }

    private function withNoise(MeasurementDataV2 $window, NoiseWindow $noise): MeasurementDataV3
    {
        return new MeasurementDataV3(
            temperature: $window->temperature,
            humidity: $window->humidity,
            pressure: $window->pressure,
            temperatureMin: $window->temperatureMin,
            temperatureMax: $window->temperatureMax,
            humidityMin: $window->humidityMin,
            humidityMax: $window->humidityMax,
            pressureMin: $window->pressureMin,
            pressureMax: $window->pressureMax,
            samples: $window->samples,
            noise: $noise,
        );
    }

    /** Hours of direct sun on the balcony. */
    private function inAfternoonSun(int $slot): bool
    {
        $hour = $this->localHour($slot);

        return $hour >= 12 && $hour < 17;
    }

    private function localHour(int $slot): int
    {
        return (int) now()->setTimestamp($slot)->setTimezone('Europe/Prague')->format('G');
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
