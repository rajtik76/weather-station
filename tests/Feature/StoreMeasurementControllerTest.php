<?php

declare(strict_types=1);
use App\Enums\ProtocolVersion;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
        'sensor_id' => Sensor::query()->where('name', 'test-sensor')->sole()->id,
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
        'sensor_id' => Sensor::query()->where('name', 'test-sensor')->sole()->id,
        'timestamp' => now()->subMinutes(20)->timestamp,
    ]);
    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_id' => Sensor::query()->where('name', 'test-sensor')->sole()->id,
        'timestamp' => now()->subMinutes(10)->timestamp,
    ]);
    assertDatabaseHas(Measurement::class, [
        'protocol_version' => ProtocolVersion::V1,
        'sensor_id' => Sensor::query()->where('name', 'test-sensor')->sole()->id,
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

    Measurement::factory()->for(Sensor::factory()->create(['name' => 'test-sensor']))->create([
        'protocol_version' => ProtocolVersion::V1->value,
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
        'sensor_id' => Sensor::query()->where('name', 'test-sensor')->sole()->id,
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

it('pings the uptime monitor once a batch is stored', function (): void {
    config()->set('sensor.heartbeat_url', 'https://status.example.com/api/push/abc');
    Http::fake();

    postJson('/api/v1/measurement', [
        'sensor_name' => 'bme280',
        'protocol_version' => 1,
        'measurements' => [
            ['timestamp' => 1757000000, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
        ],
    ])->assertCreated();

    // Dispatched after the response; the test kernel terminates the request for us.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://status.example.com/api/push/abc');
});

it('stores a batch without pinging when no monitor is configured', function (): void {
    config()->set('sensor.heartbeat_url');
    Http::fake();

    postJson('/api/v1/measurement', [
        'sensor_name' => 'bme280',
        'protocol_version' => 1,
        'measurements' => [
            ['timestamp' => 1757000001, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
        ],
    ])->assertCreated();

    Http::assertNothingSent();
});

it('registers a sensor on its first upload and reuses it afterwards', function (): void {
    foreach ([1757000000, 1757000600] as $timestamp) {
        postJson('/api/v1/measurement', [
            'sensor_name' => 'bme280-north',
            'protocol_version' => 1,
            'measurements' => [
                ['timestamp' => $timestamp, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
            ],
        ])->assertCreated();
    }

    assertDatabaseCount(Sensor::class, 1);
    assertDatabaseHas(Sensor::class, ['name' => 'bme280-north', 'description' => null]);
    expect(Sensor::query()->sole()->measurements()->count())->toBe(2);
});

/**
 * The `station` object a V2 firmware sends beside its measurements.
 *
 * @return array<string, int|string>
 */
function stationReport(): array
{
    return [
        'firmware' => '2.1.0',
        'reset_reason' => 'task watchdog',
        'uptime' => 4212,
        'heap_free' => 187_000,
        'heap_min' => 151_000,
        'ssid' => 'home',
        'ip' => '192.168.0.42',
        'rssi' => -67,
        'wifi_network' => 1,
        'wifi_switches' => 2,
        'buffered' => 3,
        'upload_failures' => 0,
    ];
}

it('keeps the station report that came with a batch', function (): void {
    postJson('/api/v1/measurement', [
        'sensor_name' => 'bme280',
        'protocol_version' => 1,
        'measurements' => [
            ['timestamp' => 1757000000, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
        ],
        'station' => stationReport(),
    ])->assertCreated();

    $report = StationReport::query()->sole();

    expect($report->sensor_id)->toBe(Sensor::query()->where('name', 'bme280')->sole()->id)
        ->and($report->data)->toEqualCanonicalizing(stationReport());
});

it('keeps only the report fields it validates', function (): void {
    postJson('/api/v1/measurement', [
        'sensor_name' => 'bme280',
        'protocol_version' => 1,
        'measurements' => [
            ['timestamp' => 1757000000, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
        ],
        'station' => [...stationReport(), 'debug' => str_repeat('x', 10_000)],
    ])->assertCreated();

    expect(StationReport::query()->sole()->data)->toEqualCanonicalizing(stationReport());
});

it('stores a batch that carries no station report', function (): void {
    postJson('/api/v1/measurement', [
        'sensor_name' => 'bme280',
        'protocol_version' => 1,
        'measurements' => [
            ['timestamp' => 1757000000, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
        ],
    ])->assertCreated();

    assertDatabaseCount(Measurement::class, 1);
    assertDatabaseCount(StationReport::class, 0);
});

it('keeps a report per batch rather than the latest only', function (): void {
    foreach ([1757000000, 1757000600] as $timestamp) {
        postJson('/api/v1/measurement', [
            'sensor_name' => 'bme280',
            'protocol_version' => 1,
            'measurements' => [
                ['timestamp' => $timestamp, 'temperature' => 2602, 'humidity' => 4871, 'pressure' => 97389],
            ],
            'station' => stationReport(),
        ])->assertCreated();
    }

    assertDatabaseCount(StationReport::class, 2);
});
