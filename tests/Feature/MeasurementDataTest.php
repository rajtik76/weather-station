<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;

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

it('reads factory generated measurement data', function (): void {
    $measurement = Measurement::factory()->create();

    expect($measurement->refresh()->data)->toBeInstanceOf(MeasurementDataV1::class);
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
        ->toBe(['temperature', 'humidity', 'pressure']);
});
