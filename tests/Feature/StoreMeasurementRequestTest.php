<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;

use function Pest\Laravel\postJson;

describe('sensor name', function (): void {
    it('require sensor name', function (): void {
        postJson('/api/v1/measurement', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sensor_name' => 'The sensor name field is required.']);
    });

    it('sensor name minimum length', function (): void {
        postJson('/api/v1/measurement', ['sensor_name' => 'ab'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sensor_name' => 'The sensor name field must be at least 3 characters.']);
    });

    it('sensor name maximum length', function (): void {
        postJson('/api/v1/measurement', ['sensor_name' => str('a')->repeat(51)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sensor_name' => 'The sensor name field must not be greater than 50 characters.']);
    });
});

describe('protocol version', function (): void {
    it('require protocol version', function (): void {
        postJson('/api/v1/measurement', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['protocol_version' => 'The protocol version field is required.']);
    });

    it('unknown protocol version', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => 99999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['protocol_version' => 'The selected protocol version is invalid.']);
    });
});

describe('measurements', function (): void {
    it('require measurements', function (): void {
        postJson('/api/v1/measurement', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements' => 'The measurements field is required.']);
    });

    it('require at least 1 measurement', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements' => 'The measurements field is required.']);
    });

    it('rejects more measurements than one batch may carry', function (): void {
        $measurements = array_fill(0, 501, ['timestamp' => now()->timestamp, 'temperature' => 200, 'humidity' => 40, 'pressure' => 100000]);

        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => $measurements])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements' => 'The measurements field must not have more than 500 items.']);
    });

    it('check minimum timestamp', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['timestamp' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.timestamp' => 'The measurements.0.timestamp field must be at least 1.']);
    });

    it('check maximum timestamp', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['timestamp' => 4294967296]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.timestamp' => 'The measurements.0.timestamp field must not be greater than 4294967295.']);
    });

    it('required timestamp', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['aaa']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.timestamp' => 'The measurements.0.timestamp field is required.']);
    });

    it('check if timestamp is integer', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['timestamp' => '123a']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.timestamp' => 'The measurements.0.timestamp field must be an integer.']);
    });

    it('check if timestamp is U', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['timestamp' => '123a']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.timestamp' => 'The measurements.0.timestamp field must match the format U.']);
    });

    it('check if timestamp is valid', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['timestamp' => 1788332955]]])
            ->assertJsonMissingValidationErrors('measurements.0.timestamp');
    });

    it('required temperature', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['aaa']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.temperature' => 'The measurements.0.temperature field is required.']);
    });

    it('check if temperature is integer', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => '123a']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.temperature' => 'The measurements.0.temperature field must be an integer.']);
    });

    it('check minimum temperature', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => -4001]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.temperature' => 'The measurements.0.temperature field must be at least -4000.']);
    });

    it('check maximum temperature', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => 8501]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.temperature' => 'The measurements.0.temperature field must not be greater than 8500.']);
    });

    it('check if temperature is valid', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => -4000]]])
            ->assertJsonMissingValidationErrors('measurements.0.temperature');
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => 0]]])
            ->assertJsonMissingValidationErrors('measurements.0.temperature');
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => 8500]]])
            ->assertJsonMissingValidationErrors('measurements.0.temperature');
    });

    it('required humidity', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['aaa']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.humidity' => 'The measurements.0.humidity field is required.']);
    });

    it('check if humidity is integer', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['humidity' => '123a']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.humidity' => 'The measurements.0.humidity field must be an integer.']);
    });

    it('check minimum humidity', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['humidity' => -1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.humidity' => 'The measurements.0.humidity field must be at least 0.']);
    });

    it('check maximum humidity', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['humidity' => 10001]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.humidity' => 'The measurements.0.humidity field must not be greater than 10000.']);
    });

    it('check if humidity is valid', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['humidity' => 0]]])
            ->assertJsonMissingValidationErrors('measurements.0.humidity');
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['humidity' => 10000]]])
            ->assertJsonMissingValidationErrors('measurements.0.humidity');
    });

    it('required pressure', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['aaa']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.pressure' => 'The measurements.0.pressure field is required.']);
    });

    it('check if pressure is integer', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['pressure' => '123a']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.pressure' => 'The measurements.0.pressure field must be an integer.']);
    });

    it('check minimum pressure', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['pressure' => 29999]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.pressure' => 'The measurements.0.pressure field must be at least 30000.']);
    });

    it('check maximum pressure', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['pressure' => 110001]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.pressure' => 'The measurements.0.pressure field must not be greater than 110000.']);
    });

    it('check if pressure is valid', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['pressure' => 33000]]])
            ->assertJsonMissingValidationErrors('measurements.0.pressure');
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['pressure' => 110000]]])
            ->assertJsonMissingValidationErrors('measurements.0.pressure');
    });
});

it('has valid request data', function (): void {
    postJson('/api/v1/measurement', [
        'sensor_name' => 'test-sensor',
        'protocol_version' => ProtocolVersion::V1->value,
        'measurements' => [
            [
                'timestamp' => now()->timestamp,
                'temperature' => 90,
                'humidity' => 10000,
                'pressure' => 30000,
            ],
        ],
    ])
        ->assertStatus(201);
});
