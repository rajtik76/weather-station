<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Database\Seeder;

class MeasurementSeeder extends Seeder
{
    /** Matches the station's reporting interval. */
    private const int STEP_SECONDS = 600;

    private const string SENSOR = 'sensor-001';

    /**
     * A month of readings for the station's actual location.
     *
     * The values are real observations for Plzen-Slovany (Open-Meteo hourly
     * reanalysis, 345 m elevation), not a synthetic model. A model is easy to
     * get wrong in ways that are obvious to anyone who knows the place: the
     * first version of this seeder centred pressure on 1013 hPa, which is sea
     * level. A BME280 at 345 m reads about 977, so every row was 36 hPa off
     * and no amount of realistic-looking noise would have hidden it.
     *
     * The file holds 744 hourly points, exactly 31 days, which is six
     * ten-minute readings per hour. Values are interpolated between hours and
     * jittered by a few hundredths so consecutive readings differ the way a
     * real sensor's do.
     */
    public function run(): void
    {
        $observations = $this->observations();
        $hours = count($observations['temperature_c']);
        $perHour = 3600 / self::STEP_SECONDS;
        $count = $hours * $perHour;

        // Fixed seed: reseeding returns the same month, so screenshots and
        // manual comparisons stay reproducible.
        mt_srand(1898);

        $end = (int) (floor(now()->getTimestamp() / self::STEP_SECONDS) * self::STEP_SECONDS);
        $start = $end - ($count - 1) * self::STEP_SECONDS;

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $position = $i / $perHour;
            $hour = (int) floor($position);
            $into = $position - $hour;

            $temperature = $this->between($observations['temperature_c'], $hour, $into) + mt_rand(-4, 4) / 100;
            $humidity = $this->between($observations['humidity_pct'], $hour, $into) + mt_rand(-20, 20) / 100;
            $pressure = $this->between($observations['pressure_hpa'], $hour, $into) + mt_rand(-3, 3) / 100;

            $rows[] = [
                'sensor_name' => self::SENSOR,
                'protocol_version' => ProtocolVersion::V1->value,
                'timestamp' => $start + $i * self::STEP_SECONDS,
                // Protocol units: hundredths for temperature and humidity,
                // pascals for pressure.
                'data' => (string) new MeasurementDataV1(
                    temperature: (int) round($temperature * 100),
                    humidity: (int) round(max(0.0, min(100.0, $humidity)) * 100),
                    pressure: (int) round($pressure * 100),
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // One insert per reading would be over four thousand round trips.
        foreach (array_chunk($rows, 500) as $chunk) {
            Measurement::insert($chunk);
        }
    }

    /**
     * Linear interpolation between two hourly observations.
     *
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
