<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\postJson;

it('reads stored measurement data back as a versioned value object', function (): void {
    freezeTime();

    postJson('api/v1/measurement', [
        'protocol_version' => ProtocolVersion::V1->value,
        'sensor_name' => 'test-sensor',
        'measurements' => [
            ['timestamp' => now()->timestamp, 'temperature' => 200, 'humidity' => 40, 'pressure' => 100000],
        ],
    ])->assertCreated();

    $measurement = Measurement::query()->sole();

    expect($measurement->data)->toBeInstanceOf(MeasurementDataV1::class)
        ->and($measurement->data->temperature)->toBe(200)
        ->and($measurement->data->humidity)->toBe(40)
        ->and($measurement->data->pressure)->toBe(100000)
        ->and($measurement->data->protocolVersion)->toBe(ProtocolVersion::V1)
        ->and($measurement->protocol_version)->toBe(ProtocolVersion::V1);
});

it('reads a stored V2 window back with its extremes and sample count', function (): void {
    freezeTime();

    postJson('api/v1/measurement', [
        'protocol_version' => ProtocolVersion::V2->value,
        'sensor_name' => 'test-sensor',
        'measurements' => [
            [
                'timestamp' => now()->timestamp,
                'temperature' => 2134, 'temperature_min' => 2101, 'temperature_max' => 2177,
                'humidity' => 5812, 'humidity_min' => 5700, 'humidity_max' => 5900,
                'pressure' => 97389, 'pressure_min' => 97380, 'pressure_max' => 97395,
                'samples' => 20,
            ],
        ],
    ])->assertCreated();

    $measurement = Measurement::query()->sole();

    expect($measurement->data)->toEqual(new MeasurementDataV2(
        temperature: 2134, humidity: 5812, pressure: 97389,
        temperatureMin: 2101, temperatureMax: 2177,
        humidityMin: 5700, humidityMax: 5900,
        pressureMin: 97380, pressureMax: 97395,
        samples: 20,
    ))
        ->and($measurement->data->protocolVersion)->toBe(ProtocolVersion::V2)
        ->and($measurement->protocol_version)->toBe(ProtocolVersion::V2);
});

it('treats a V1 reading as its own extreme with one sample', function (): void {
    $reading = new MeasurementDataV1(temperature: 2134, humidity: 5812, pressure: 97389);

    expect([$reading->temperatureMin, $reading->temperatureMax])->toBe([2134, 2134])
        ->and([$reading->humidityMin, $reading->humidityMax])->toBe([5812, 5812])
        ->and([$reading->pressureMin, $reading->pressureMax])->toBe([97389, 97389])
        ->and($reading->samples)->toBe(1)
        // The blob keeps only what was sent; the extremes are implied, not stored.
        ->and($reading->jsonSerialize())->toBe(['temperature' => 2134, 'humidity' => 5812, 'pressure' => 97389]);
});

it('reads factory generated measurement data', function (): void {
    $measurement = Measurement::factory()->create();

    expect($measurement->refresh()->data)->toBeInstanceOf(MeasurementDataV1::class);
});

it('reads a V2 factory window', function (): void {
    $measurement = Measurement::factory()->v2()->create();

    $data = $measurement->refresh()->data;

    expect($data)->toBeInstanceOf(MeasurementDataV2::class)
        ->and($data->temperatureMin)->toBeLessThanOrEqual($data->temperature)
        ->and($data->temperatureMax)->toBeGreaterThanOrEqual($data->temperature);
});

it('rejects a stored V2 blob missing one of its fields', function (): void {
    $measurement = Measurement::factory()->v2()->create([
        'data' => json_encode(['temperature' => 200, 'humidity' => 40, 'pressure' => 100000]),
    ]);

    expect(fn () => $measurement->refresh()->data)
        ->toThrow(UnexpectedValueException::class, 'Missing or invalid field [temperature_min] for protocol version 2.');
});

it('rejects a stored blob missing a field required by its protocol version', function (): void {
    $measurement = Measurement::factory()->create([
        'data' => json_encode(['temperature' => 200, 'humidity' => 40]),
    ]);

    expect(fn () => $measurement->refresh()->data)
        ->toThrow(UnexpectedValueException::class, 'Missing or invalid field [pressure] for protocol version 1.');
});

it('resolves the value object class from the protocol version', function (): void {
    expect(ProtocolVersion::V1->dataClass())->toBe(MeasurementDataV1::class)
        ->and(ProtocolVersion::V1->hydrate(['temperature' => 1, 'humidity' => 2, 'pressure' => 30000]))
        ->toBeInstanceOf(MeasurementDataV1::class)
        ->and(array_keys(ProtocolVersion::V1->validationRules()))
        ->toBe(['temperature', 'humidity', 'pressure'])
        ->and(ProtocolVersion::V2->dataClass())->toBe(MeasurementDataV2::class)
        ->and(array_keys(ProtocolVersion::V2->validationRules()))
        ->toBe(['temperature', 'humidity', 'pressure', 'temperature_min', 'temperature_max', 'humidity_min', 'humidity_max', 'pressure_min', 'pressure_max', 'samples']);
});
