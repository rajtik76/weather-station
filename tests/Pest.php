<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\ReferenceTemperature;
use App\Models\Sensor;
use App\ValueObject\ChmuTemperatures;
use App\ValueObject\LightWindow;
use App\ValueObject\LocalTime;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\NoiseWindow;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\SolarPosition;
use App\ValueObject\StationSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

use function Pest\Laravel\withHeader;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature')
    ->beforeEach(function (): void {
        withHeader('Authorization', 'Bearer '.config('sensor.api_token'));
    });

expect()->extend('toBeOne', fn () => $this->toBe(1));

function chartRows(string $html): string
{
    preg_match('/data-chart-rows="([^"]*)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? '');
}

/**
 * @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}>
 */
function bucketRows(string $html): array
{
    return json_decode(chartRows($html) ?: '[]', true);
}

/**
 * @return array<int, list<int|float|null>>
 */
function lightBuckets(string $html): array
{
    preg_match('/data-light-rows="([^"]*)"/', $html, $matches);

    $filled = [];

    foreach (json_decode(html_entity_decode($matches[1] ?? '') ?: '[]', true) as $row) {
        if ($row[2] !== null) {
            $filled[$row[1]] = $row;
        }
    }

    return $filled;
}

function litWindow(int $illuminance, int $min, int $max): MeasurementDataV4
{
    return new MeasurementDataV4(
        temperature: 1200, humidity: 6000, pressure: 97000,
        temperatureMin: 1150, temperatureMax: 1250,
        humidityMin: 5900, humidityMax: 6100,
        pressureMin: 96990, pressureMax: 97010,
        samples: 20,
        light: new LightWindow(illuminance: $illuminance, illuminanceMin: $min, illuminanceMax: $max),
    );
}

/**
 * @return array<int, list<int|float|null>>
 */
function noiseBuckets(string $html): array
{
    preg_match('/data-noise-rows="([^"]*)"/', $html, $matches);

    $filled = [];

    foreach (json_decode(html_entity_decode($matches[1] ?? '') ?: '[]', true) as $row) {
        if ($row[2] !== null) {
            $filled[$row[1]] = $row;
        }
    }

    return $filled;
}

function noisyWindow(int $laeq, int $la10, int $la90, int $lamax, int $band, int $seconds = 600): MeasurementDataV3
{
    return new MeasurementDataV3(
        temperature: 2150, humidity: 4800, pressure: 97389,
        temperatureMin: 2100, temperatureMax: 2200,
        humidityMin: 4700, humidityMax: 4900,
        pressureMin: 97380, pressureMax: 97395,
        samples: 20,
        noise: new NoiseWindow(seconds: $seconds, laeq: $laeq, lamax: $lamax, la10: $la10, la90: $la90, bands: array_fill(0, 26, $band)),
    );
}

/**
 * @return list<int>
 */
function rainyBands(): array
{
    return array_replace(array_fill(0, 26, 3000), [15 => 4000, 16 => 4500, 17 => 4000, 25 => 5000]);
}

/**
 * @return array<int, array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}>
 */
function filledBuckets(string $html): array
{
    $filled = [];

    foreach (bucketRows($html) as $row) {
        if ($row[1] !== null) {
            $filled[$row[5]] = $row;
        }
    }

    return $filled;
}

/**
 * @return list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}>
 */
function navigatorRows(string $html): array
{
    preg_match('/data-navigator-rows="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

/**
 * @return list<array{0: int, 1: string, 2: ?string}>
 */
function chartEvents(string $html): array
{
    preg_match('/data-chart-events="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

function spectrumWindow(float $high, float $ring, float $neighbours = 40.0, float $laeq = 65.0, float $la90 = 60.0): MeasurementDataV3
{
    $bands = array_fill(0, 26, 3000);
    $bands[15] = (int) round($neighbours * 100);
    $bands[17] = (int) round($neighbours * 100);
    $bands[16] = (int) round(($neighbours + $ring) * 100);
    $bands[25] = (int) round($high * 100);

    return new MeasurementDataV3(
        temperature: 2150, humidity: 4800, pressure: 97389,
        temperatureMin: 2100, temperatureMax: 2200,
        humidityMin: 4700, humidityMax: 4900,
        pressureMin: 97380, pressureMax: 97395,
        samples: 20,
        noise: new NoiseWindow(seconds: 600, laeq: (int) round($laeq * 100), lamax: (int) round(($laeq + 7.0) * 100), la10: 6700, la90: (int) round($la90 * 100), bands: $bands),
    );
}

/** @return list<Measurement> */
function correctionHistory(Sensor $sensor, int $end, ?int $missingAt = null): array
{
    $rows = [];

    for ($index = 0; $index <= 6; $index++) {
        $rows[] = Measurement::factory()->for($sensor)->create([
            'timestamp' => $end - (6 - $index) * 600,
            'protocol_version' => ProtocolVersion::V4,
            'data' => (string) ($index === $missingAt
                ? new MeasurementDataV4(temperature: 1200, humidity: 6000, pressure: 97000,
                    temperatureMin: 1200, temperatureMax: 1200, humidityMin: 6000, humidityMax: 6000,
                    pressureMin: 97000, pressureMax: 97000, samples: 20)
                : litWindow(140000, 140000, 140000)),
        ]);
    }

    return $rows;
}

/** @return array{sensor: Sensor, from: int, validateFrom: int, until: int, features: ShieldFeatures} */
function heatingCalibrationData(): array
{
    $sensor = Sensor::factory()->create();
    $from = Date::parse('2026-10-01', LocalTime::TIMEZONE)->getTimestamp();
    $validateFrom = Date::parse('2026-10-08', LocalTime::TIMEZONE)->getTimestamp();
    $until = Date::parse('2026-10-11', LocalTime::TIMEZONE)->getTimestamp();
    $features = new ShieldFeatures(StationSite::LATITUDE, StationSite::LONGITUDE, StationSite::WALL_AZIMUTH);
    $measurements = $references = [];
    $createdAt = now()->toDateTimeString();

    for ($timestamp = $from + 570; $timestamp < $until; $timestamp += 600) {
        $sun = SolarPosition::at($timestamp - 300, StationSite::LATITUDE, StationSite::LONGITUDE);
        $lit = $sun->faces(StationSite::WALL_AZIMUTH);
        $temperature = $lit ? 1300 : 1000;
        $lux = $lit ? 200000 : 0;
        $data = new MeasurementDataV4(
            temperature: $temperature, humidity: 6000, pressure: 97000,
            temperatureMin: $temperature, temperatureMax: $temperature,
            humidityMin: 6000, humidityMax: 6000,
            pressureMin: 97000, pressureMax: 97000,
            samples: 20,
            light: new LightWindow($lux, $lux, $lux),
        );
        $measurements[] = [
            'sensor_id' => $sensor->id,
            'timestamp' => $timestamp,
            'protocol_version' => ProtocolVersion::V4->value,
            'data' => (string) $data,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        $references[] = [
            'station' => ChmuTemperatures::STATION,
            'timestamp' => (int) round($timestamp / 600) * 600,
            'temperature' => 10.0,
            'quality' => 0,
            'source' => 'calibration.json',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    foreach (array_chunk($measurements, 300) as $chunk) {
        Measurement::query()->insert($chunk);
    }

    foreach (array_chunk($references, 300) as $chunk) {
        ReferenceTemperature::query()->insert($chunk);
    }

    return ['sensor' => $sensor, 'from' => $from, 'validateFrom' => $validateFrom, 'until' => $until, 'features' => $features];
}
