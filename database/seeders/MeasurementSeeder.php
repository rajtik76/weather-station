<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\LightWindow;
use App\ValueObject\LocalTime;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\NoiseWindow;
use Illuminate\Database\Seeder;

class MeasurementSeeder extends Seeder
{
    private const int STEP_SECONDS = 600;

    private const int SAMPLES_PER_WINDOW = 20;

    /**
     * @var list<array{name: string, description: string, warmerBy: float, drierBy: float, skipDays: int, v2FromDay: int, v3FromDay: ?int, v4FromDay: ?int, sunSpikes: bool, firmware: string, board: ?string}>
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
            'v4FromDay' => 30,
            'sunSpikes' => false,
            'firmware' => '4.0.0',
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
            'v4FromDay' => null,
            'sunSpikes' => true,
            'firmware' => '2.1.0',
            'board' => null,
        ],
    ];

    /** LAeq per local hour, dB. */
    private const array NOISE_BY_HOUR = [42, 41, 40, 40, 41, 44, 50, 56, 58, 57, 56, 56, 57, 56, 56, 57, 58, 59, 57, 54, 51, 48, 46, 44];

    /** Third-octave bands 25 Hz to 8 kHz against LAeq, 0.01 dB; unweighted, so the low end sits above LAeq. */
    private const array BAND_OFFSETS = [935, 775, 556, 718, 315, -173, -304, -655, -1038, -980, -1169, -1245, -1157, -1053, -1187, -1013, -814, -709, -810, -1287, -1793, -2166, -2428, -2582, -2632, -3279];

    /** Showers as [from, to] hours before the end of the record. */
    private const array SHOWERS = [[20.0, 18.5], [44.0, 43.5]];

    private const float LATITUDE = 49.73;

    private const float LONGITUDE = 13.40;

    /** Open-sky illuminance, lx: at the horizon, and added per unit of sin(sun height). */
    private const float HORIZON_LUX = 400.0;

    private const float SUN_LUX = 110_000.0;

    /** Share of open sky reaching the VEML7700 behind the louvers. */
    private const float SHIELD_SHARE = 0.08;

    /** Local hour until which the morning sun shines straight through the east louvers. */
    private const int MORNING_SUN_UNTIL = 10;

    private const float MORNING_SUN_GAIN = 4.0;

    /** Real hourly Open-Meteo data for Plzen-Slovany (345 m): station pressure is ~977 hPa, not sea level 1013. */
    public function run(): void
    {
        $observations = $this->observations();
        $hours = count($observations['temperature_c']);
        $perHour = 3600 / self::STEP_SECONDS;
        $count = $hours * $perHour;

        // Fixed seed: a reseed returns the same month.
        mt_srand(1898);

        // Last closed slot: a stamp in an open one would lie in the future.
        $end = (int) (floor(now()->getTimestamp() / self::STEP_SECONDS) - 1) * self::STEP_SECONDS;
        $start = $end - ($count - 1) * self::STEP_SECONDS;

        foreach (self::SENSORS as $station) {
            // Model events are off in seeders: pass the slug by hand.
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

                // Protocol units: 0.01.
                $t = (int) round($temperature * 100);
                $h = (int) round(max(0.0, min(100.0, $humidity)) * 100);
                $p = (int) round($pressure * 100);

                $slot = $start + $i * self::STEP_SECONDS;
                $version = match (true) {
                    $station['v4FromDay'] !== null && $i >= $station['v4FromDay'] * 24 * $perHour => ProtocolVersion::V4,
                    $station['v3FromDay'] !== null && $i >= $station['v3FromDay'] * 24 * $perHour => ProtocolVersion::V3,
                    $i >= $station['v2FromDay'] * 24 * $perHour => ProtocolVersion::V2,
                    default => ProtocolVersion::V1,
                };
                $window = fn (): MeasurementDataV2 => $this->window($t, $h, $p, $station['sunSpikes'] && $this->inAfternoonSun($slot));

                $rows[] = [
                    'sensor_id' => $sensor->id,
                    'protocol_version' => $version->value,
                    // Stamped by the last reading, 30 s short of the slot's end.
                    'timestamp' => $version === ProtocolVersion::V1 ? $slot : $slot + self::STEP_SECONDS - 30,
                    'data' => (string) match ($version) {
                        ProtocolVersion::V1 => new MeasurementDataV1(temperature: $t, humidity: $h, pressure: $p),
                        ProtocolVersion::V2 => $window(),
                        ProtocolVersion::V3 => $this->withNoise($window(), $this->noise($slot, $this->isShowering($slot, $end))),
                        ProtocolVersion::V4 => $this->withLight(
                            $this->withNoise($window(), $this->noise($slot, $this->isShowering($slot, $end))),
                            $this->light($slot, $this->isShowering($slot, $end)),
                        ),
                    },
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Measurement::insert($chunk);
            }

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

    private function noise(int $slot, bool $isShowering): NoiseWindow
    {
        $isLoud = mt_rand(0, 11) === 0;
        $laeq = self::NOISE_BY_HOUR[$this->localHour($slot)] * 100 + mt_rand(-150, 150) + ($isLoud ? mt_rand(200, 600) : 0);

        if ($isShowering) {
            $laeq = mt_rand(6400, 7300);
        }

        $bands = array_map(
            fn (int $offset): int => max(0, $laeq + $offset + mt_rand(-150, 150)),
            self::BAND_OFFSETS,
        );

        if ($isShowering) {
            // Drops on the shield, as RainDetector hears them: loud at 8 kHz, the shell ringing at 1 kHz.
            $bands[25] = mt_rand(4900, 6000);
            $bands[16] = max($bands[15], $bands[17]) + mt_rand(250, 500);
        }

        return new NoiseWindow(
            seconds: mt_rand(0, 3) === 0 ? mt_rand(570, 599) : self::STEP_SECONDS,
            laeq: $laeq,
            lamax: $laeq + mt_rand(600, 1400) + ($isLoud ? mt_rand(1000, 2500) : 0),
            la10: $laeq + mt_rand(150, 350),
            la90: $laeq - mt_rand(450, 900),
            bands: $bands,
        );
    }

    private function isShowering(int $slot, int $end): bool
    {
        $hoursBeforeEnd = ($end - $slot) / 3600;

        foreach (self::SHOWERS as [$from, $to]) {
            if ($hoursBeforeEnd <= $from && $hoursBeforeEnd > $to) {
                return true;
            }
        }

        return false;
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

    /** Light behind the shield, 0.01 lx. */
    private function light(int $slot, bool $isShowering): LightWindow
    {
        $height = $this->sunHeight($slot + intdiv(self::STEP_SECONDS, 2));

        $openSky = match (true) {
            $height < -6.0 => mt_rand(0, 5) / 100 / self::SHIELD_SHARE,
            $height < 0.0 => self::HORIZON_LUX * 10 ** ($height / 2),
            default => self::HORIZON_LUX + self::SUN_LUX * sin(deg2rad($height)),
        };

        // One cover per hour, off a hash of the hour (not mt_rand(), which would need reseeding); a shower is dark.
        $cover = $isShowering ? 0.12 : 0.25 + 0.75 * (crc32((string) intdiv($slot, 3600)) % 1000) / 999;

        $isMorningSun = $height > 3.0 && $this->localHour($slot) < self::MORNING_SUN_UNTIL && $cover > 0.7;
        $lux = $openSky * self::SHIELD_SHARE * ($height > 0.0 ? $cover : 1.0) * ($isMorningSun ? self::MORNING_SUN_GAIN : 1.0);

        $mean = (int) round($lux * 100);
        $spread = $height > 0.0 ? mt_rand(5, 30) / 100 : 0.02;

        return new LightWindow(
            illuminance: $mean,
            illuminanceMin: (int) floor($mean * (1 - $spread)),
            illuminanceMax: min(15_000_000, (int) ceil($mean * (1 + $spread))),
        );
    }

    /** Sun height in degrees; equation of time left out. */
    private function sunHeight(int $timestamp): float
    {
        $day = (int) gmdate('z', $timestamp) + 1;
        $declination = deg2rad(23.44 * sin(deg2rad(360 / 365 * ($day + 284))));
        $solarHours = ($timestamp % 86_400) / 3600 + self::LONGITUDE / 15;
        $hourAngle = deg2rad(15 * ($solarHours - 12));
        $latitude = deg2rad(self::LATITUDE);

        return rad2deg(asin(sin($latitude) * sin($declination) + cos($latitude) * cos($declination) * cos($hourAngle)));
    }

    private function withLight(MeasurementDataV3 $window, LightWindow $light): MeasurementDataV4
    {
        return new MeasurementDataV4(
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
            noise: $window->noise,
            light: $light,
        );
    }

    private function inAfternoonSun(int $slot): bool
    {
        $hour = $this->localHour($slot);

        return $hour >= 12 && $hour < 17;
    }

    private function localHour(int $slot): int
    {
        return (int) now()->setTimestamp($slot)->setTimezone(LocalTime::TIMEZONE)->format('G');
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
