<?php

declare(strict_types=1);

use App\ValueObject\LightWindow;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\NoiseWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use function Pest\Laravel\withHeader;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature')
    ->beforeEach(function (): void {
        withHeader('Authorization', 'Bearer '.config('sensor.api_token'));
    });

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * Reading the chart payloads out of a rendered page, and building the
 * windows the chart tests feed it.
 */

/**
 * The window's payload only; the raw HTML also carries the navigator's,
 * which spans the whole record.
 */
function chartRows(string $html): string
{
    preg_match('/data-chart-rows="([^"]*)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? '');
}

/**
 * The window's payload decoded, one row per bucket - holes included.
 *
 * @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}>
 */
function bucketRows(string $html): array
{
    return json_decode(chartRows($html) ?: '[]', true);
}

/**
 * The window's light payload, only the buckets that hold light, keyed by their epoch.
 *
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
 * The window's noise payload, only the buckets that hold noise, keyed by their epoch.
 *
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

/**
 * A V3 window with noise, every band at the same level.
 */
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
 * A spectrum RainDetector hears as rain, in hundredths of dB: loud at 8 kHz,
 * with the shield ringing at 1 kHz above both its neighbours.
 *
 * @return list<int>
 */
function rainyBands(): array
{
    return array_replace(array_fill(0, 26, 3000), [15 => 4000, 16 => 4500, 17 => 4000, 25 => 5000]);
}

/**
 * Only the buckets a reading landed in, keyed by their epoch.
 *
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
 * The navigator's own payload, which always spans the whole record.
 *
 * @return list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}>
 */
function navigatorRows(string $html): array
{
    preg_match('/data-navigator-rows="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

/**
 * The events the charts are told to mark.
 *
 * @return list<array{0: int, 1: string, 2: ?string}>
 */
function chartEvents(string $html): array
{
    preg_match('/data-chart-events="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

/**
 * One horizon as the forecast service stores it.
 *
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

/**
 * A V3 window whose spectrum carries the given 8 kHz level and 1 kHz ring
 * over its neighbours, in dB.
 */
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
