<?php

declare(strict_types=1);
use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\freezeTime;
use function Pest\Laravel\postJson;

function storedMeasurement(int $timestamp): Measurement
{
    return Measurement::query()->where('timestamp', $timestamp)->sole();
}

it('can store measurement', function (): void {
    freezeTime();

    postJson('api/v1/measurement', [
        'protocol_version' => ProtocolVersion::V1->value,
        'sensor_name' => 'test-sensor',
        'measurements' => [
            ['timestamp' => now()->timestamp, 'temperature' => 200, 'humidity' => 40, 'pressure' => 100000],
        ],
    ])->assertCreated();

    assertDatabaseCount(Measurement::class, 1);
    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_name' => 'test-sensor',
        'timestamp' => now()->timestamp,
    ]);

    expect(storedMeasurement(now()->getTimestamp())->data)
        ->toEqual(new MeasurementDataV1(temperature: 200, humidity: 40, pressure: 100000));
});

it('can store multiple measurements', function (): void {
    freezeTime();

    postJson('api/v1/measurement', [
        'protocol_version' => ProtocolVersion::V1->value,
        'sensor_name' => 'test-sensor',
        'measurements' => [
            ['timestamp' => now()->subMinutes(20)->timestamp, 'temperature' => 220, 'humidity' => 42, 'pressure' => 102000],
            ['timestamp' => now()->subMinutes(10)->timestamp, 'temperature' => 210, 'humidity' => 41, 'pressure' => 101000],
            ['timestamp' => now()->timestamp, 'temperature' => 200, 'humidity' => 40, 'pressure' => 100000],
        ],
    ])->assertCreated();

    assertDatabaseCount(Measurement::class, 3);

    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_name' => 'test-sensor',
        'timestamp' => now()->subMinutes(20)->timestamp,
    ]);
    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_name' => 'test-sensor',
        'timestamp' => now()->subMinutes(10)->timestamp,
    ]);
    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_name' => 'test-sensor',
        'timestamp' => now()->timestamp,
    ]);

    expect(storedMeasurement(now()->subMinutes(20)->getTimestamp())->data)
        ->toEqual(new MeasurementDataV1(temperature: 220, humidity: 42, pressure: 102000))
        ->and(storedMeasurement(now()->subMinutes(10)->getTimestamp())->data)
        ->toEqual(new MeasurementDataV1(temperature: 210, humidity: 41, pressure: 101000))
        ->and(storedMeasurement(now()->getTimestamp())->data)
        ->toEqual(new MeasurementDataV1(temperature: 200, humidity: 40, pressure: 100000));
});

it('idempotency replace existing data', function (): void {
    freezeTime();

    Measurement::factory()->create([
        'protocol_version' => ProtocolVersion::V1->value,
        'sensor_name' => 'test-sensor',
        'timestamp' => now()->timestamp,
        'data' => (string) new MeasurementDataV1(
            temperature: 200,
            humidity: 40,
            pressure: 100000,
        ),
    ]);

    postJson('api/v1/measurement', [
        'protocol_version' => ProtocolVersion::V1->value,
        'sensor_name' => 'test-sensor',
        'measurements' => [
            ['timestamp' => now()->timestamp, 'temperature' => 250, 'humidity' => 45, 'pressure' => 100005],
        ],
    ])->assertCreated();

    assertDatabaseCount(Measurement::class, 1);
    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_name' => 'test-sensor',
        'timestamp' => now()->timestamp,
    ]);

    expect(storedMeasurement(now()->getTimestamp())->data)
        ->toEqual(new MeasurementDataV1(temperature: 250, humidity: 45, pressure: 100005));
});

it('keeps measurements of different sensors sharing one timestamp', function (): void {
    freezeTime();

    foreach (['sensor-a', 'sensor-b'] as $sensorName) {
        postJson('api/v1/measurement', [
            'protocol_version' => ProtocolVersion::V1->value,
            'sensor_name' => $sensorName,
            'measurements' => [
                ['timestamp' => now()->timestamp, 'temperature' => 200, 'humidity' => 40, 'pressure' => 100000],
            ],
        ])->assertCreated();
    }

    assertDatabaseCount(Measurement::class, 2);
});
