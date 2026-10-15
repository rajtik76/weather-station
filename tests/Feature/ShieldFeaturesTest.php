<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\ShieldFeatures;
use App\ValueObject\StationSite;

/**
 * @param  list<int|null>  $lux
 * @return list<Measurement>
 */
function shieldReadings(int $start, array $lux): array
{
    $readings = [];

    foreach ($lux as $index => $value) {
        $readings[] = Measurement::factory()->make([
            'timestamp' => $start + $index * 600,
            'protocol_version' => ProtocolVersion::V4,
            'data' => (string) ($value === null
                ? new MeasurementDataV4(temperature: 1200, humidity: 6000, pressure: 97000,
                    temperatureMin: 1200, temperatureMax: 1200, humidityMin: 6000, humidityMax: 6000,
                    pressureMin: 97000, pressureMax: 97000, samples: 20)
                : litWindow($value * 100, $value * 100, $value * 100)),
        ]);
    }

    return $readings;
}

function balconyFeatures(): ShieldFeatures
{
    return new ShieldFeatures(StationSite::LATITUDE, StationSite::LONGITUDE, 65.0);
}

it('uses measured lux and causal heating histories after an uninterrupted hour', function (): void {
    $readings = shieldReadings(strtotime('2026-10-01T06:00:00Z'), array_fill(0, 7, 1400));

    $samples = balconyFeatures()->samples($readings);

    expect($samples)->toHaveCount(1)
        ->and($samples[0]->features[0])->toEqualWithDelta(0.9, 0.001)
        ->and($samples[0]->features[3])->toEqualWithDelta(0.8552, 0.001)
        ->and($samples[0]->features[6])->toEqualWithDelta(0.5689, 0.001);
});

it('retains cooling when the light disappears', function (): void {
    $readings = shieldReadings(strtotime('2026-10-01T06:00:00Z'), [...array_fill(0, 7, 1400), 0]);

    $samples = balconyFeatures()->samples($readings);

    expect($samples[1]->features[0])->toBe(0.0)
        ->and($samples[1]->features[3])->toBeGreaterThan(0.0)->toBeLessThan($samples[0]->features[3])
        ->and($samples[1]->features[6])->toBeGreaterThan(0.0);
});

it('does not treat bright light at night as solar heating', function (): void {
    $readings = shieldReadings(strtotime('2026-10-01T22:00:00Z'), array_fill(0, 7, 2000));

    expect(balconyFeatures()->samples($readings)[0]->features)->toBe(array_fill(0, 9, 0.0));
});

it('requires a fresh hour after a missing light observation', function (): void {
    $start = strtotime('2026-10-01T06:00:00Z');
    $readings = shieldReadings($start, [...array_fill(0, 7, 1400), null, ...array_fill(0, 7, 1400)]);

    $samples = balconyFeatures()->samples($readings);

    expect(array_column($samples, 'timestamp'))->toBe([$start + 3600, $start + 8400]);
});

it('resets thermal history after a station outage', function (): void {
    $start = strtotime('2026-10-01T06:00:00Z');
    $readings = [
        ...shieldReadings($start, array_fill(0, 7, 1400)),
        ...shieldReadings($start + 7200, array_fill(0, 7, 0)),
    ];

    $samples = balconyFeatures()->samples($readings);

    expect($samples)->toHaveCount(2)
        ->and($samples[1]->features)->toBe(array_fill(0, 9, 0.0));
});

it('does not change earlier features when later readings arrive', function (): void {
    $start = strtotime('2026-10-01T06:00:00Z');
    $readings = shieldReadings($start, array_fill(0, 7, 1400));
    $before = balconyFeatures()->samples($readings)[0];
    $after = balconyFeatures()->samples([...$readings, ...shieldReadings($start + 4200, [5000])])[0];

    expect($after)->toEqual($before);
});
