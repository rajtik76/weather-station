<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Queries\MeasurementBuckets;
use App\ValueObject\CarriesLight;
use App\ValueObject\CarriesNoise;
use App\ValueObject\ChartWindow;
use App\ValueObject\LightWindow;
use App\ValueObject\NoiseWindow;

// MeasurementBuckets reads the jsonb by key, bypassing hydrate(): a renamed key in a new version must fail here.
it('aggregates every protocol version from the keys its value object stores', function (ProtocolVersion $version): void {
    $slot = 1_790_000_400;
    $sensor = Sensor::factory()->create();
    $entry = $version->hydrate([
        'temperature' => 1234,
        'humidity' => 5678,
        'pressure' => 97_655,
        'temperature_min' => 1100,
        'temperature_max' => 1300,
        'humidity_min' => 5500,
        'humidity_max' => 5800,
        'pressure_min' => 97_600,
        'pressure_max' => 97_700,
        'samples' => 20,
        'noise' => ['seconds' => 600, 'laeq' => 4520, 'lamax' => 6810, 'la10' => 4800, 'la90' => 4100, 'bands' => range(3000, 5500, 100)],
        'illuminance' => 1_250_000,
        'illuminance_min' => 1_000_000,
        'illuminance_max' => 1_500_000,
    ]);
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $slot + 300,
        'protocol_version' => $version,
        'data' => (string) $entry,
    ]);
    $window = ChartWindow::of($slot - 3600, $slot + 600);
    $buckets = new MeasurementBuckets($sensor->id);

    $reading = $buckets->readings($window)->firstOrFail('bucket', $slot);

    expect([
        (float) $reading->t_avg, (float) $reading->t_min, (float) $reading->t_max,
        (float) $reading->h_avg, (float) $reading->h_min, (float) $reading->h_max,
        (float) $reading->p_avg, (float) $reading->p_min, (float) $reading->p_max,
    ])->toBe([
        (float) $entry->temperature, (float) $entry->temperatureMin, (float) $entry->temperatureMax,
        (float) $entry->humidity, (float) $entry->humidityMin, (float) $entry->humidityMax,
        (float) $entry->pressure, (float) $entry->pressureMin, (float) $entry->pressureMax,
    ]);

    $noise = $entry instanceof CarriesNoise ? $entry->noise : null;
    $heard = $buckets->noise($window)->firstOrFail('bucket', $slot);

    if ($noise instanceof NoiseWindow) {
        expect((float) $heard->laeq)->toEqualWithDelta($noise->laeq, 0.01)
            ->and((float) $heard->la10)->toEqualWithDelta($noise->la10, 0.01)
            ->and((float) $heard->la90)->toEqualWithDelta($noise->la90, 0.01)
            ->and((int) $heard->lamax)->toBe($noise->lamax)
            ->and(array_map(round(...), json_decode((string) $heard->bands, true)))->toEqual($noise->bands);
    } else {
        expect($heard->laeq)->toBeNull();
    }

    $light = $entry instanceof CarriesLight ? $entry->light : null;
    $lit = $buckets->light($window)->firstOrFail('bucket', $slot);

    if ($light instanceof LightWindow) {
        expect([(float) $lit->l_avg, (int) $lit->l_min, (int) $lit->l_max])
            ->toBe([(float) $light->illuminance, $light->illuminanceMin, $light->illuminanceMax]);
    } else {
        expect($lit->l_avg)->toBeNull();
    }
})->with(ProtocolVersion::cases());
