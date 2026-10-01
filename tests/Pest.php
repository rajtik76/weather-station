<?php

declare(strict_types=1);

use App\ValueObject\LightWindow;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\NoiseWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Pest\Laravel\withHeader;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature')
    ->beforeEach(function (): void {
        withHeader('Authorization', 'Bearer '.config('sensor.api_token'));
    });

expect()->extend('toBeOne', fn () => $this->toBe(1));

/** The window's payload only; the navigator's spans the whole record. */
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

/** A V4 window with light, in hundredths of a lux. */
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
 * Rain to RainDetector: loud at 8 kHz, shield ringing at 1 kHz; hundredths of dB.
 *
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

/**
 * @return array<string, mixed>
 */
function forecastHorizon(int $hours, float $temperature, float $humidity, float $rain): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $temperature - 1.44, 'mid' => $temperature, 'high' => $temperature + 1.72],
        'humidity' => ['low' => $humidity - 5, 'mid' => $humidity, 'high' => $humidity + 5],
        'pressure' => ['low' => 976.0, 'mid' => 976.47, 'high' => 977.1],
        'rain_probability' => $rain,
    ];
}

/** A V3 window with the given 8 kHz level and 1 kHz ring over its neighbours, in dB. */
function spectrumWindow(float $high, float $ring, float $neighbours = 40.0): MeasurementDataV3
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
        noise: new NoiseWindow(seconds: 600, laeq: 6500, lamax: 7200, la10: 6700, la90: 6000, bands: $bands),
    );
}
