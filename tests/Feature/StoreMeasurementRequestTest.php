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

describe('protocol V2', function (): void {
    /**
     * A window the firmware would send, one field overridden.
     *
     * @param  array<string, int|string|null>  $overrides
     * @return array<string, mixed>
     */
    function v2Window(array $overrides = []): array
    {
        return [
            'sensor_name' => 'test-sensor',
            'protocol_version' => ProtocolVersion::V2->value,
            'measurements' => [
                array_merge([
                    'timestamp' => 1788332955,
                    'temperature' => 2134, 'temperature_min' => 2101, 'temperature_max' => 2177,
                    'humidity' => 5812, 'humidity_min' => 5700, 'humidity_max' => 5900,
                    'pressure' => 97389, 'pressure_min' => 97380, 'pressure_max' => 97395,
                    'samples' => 20,
                ], $overrides),
            ],
        ];
    }

    it('accepts a window', function (): void {
        postJson('/api/v1/measurement', v2Window())->assertStatus(201);
    });

    it('accepts a window of one sample with no spread', function (): void {
        postJson('/api/v1/measurement', v2Window([
            'temperature_min' => 2134, 'temperature_max' => 2134,
            'humidity_min' => 5812, 'humidity_max' => 5812,
            'pressure_min' => 97389, 'pressure_max' => 97389,
            'samples' => 1,
        ]))->assertStatus(201);
    });

    it('requires every extreme and the sample count', function (string $field): void {
        postJson('/api/v1/measurement', v2Window([$field => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(["measurements.0.{$field}" => "The measurements.0.{$field} field is required."]);
    })->with(['temperature_min', 'temperature_max', 'humidity_min', 'humidity_max', 'pressure_min', 'pressure_max', 'samples']);

    // The message quotes the mean the extreme was measured against.
    it('rejects a minimum above the mean', function (string $channel, int $mean): void {
        postJson('/api/v1/measurement', v2Window(["{$channel}_min" => $mean + 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(["measurements.0.{$channel}_min" => "The measurements.0.{$channel}_min field must be less than or equal to {$mean}."]);
    })->with([
        'temperature' => ['temperature', 2134],
        'humidity' => ['humidity', 5812],
        'pressure' => ['pressure', 97389],
    ]);

    it('rejects a maximum below the mean', function (string $channel, int $mean): void {
        postJson('/api/v1/measurement', v2Window(["{$channel}_max" => $mean - 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(["measurements.0.{$channel}_max" => "The measurements.0.{$channel}_max field must be greater than or equal to {$mean}."]);
    })->with([
        'temperature' => ['temperature', 2134],
        'humidity' => ['humidity', 5812],
        'pressure' => ['pressure', 97389],
    ]);

    it('keeps the extremes inside the channel range', function (): void {
        postJson('/api/v1/measurement', v2Window(['temperature_min' => -4001, 'pressure_max' => 110001]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'measurements.0.temperature_min' => 'The measurements.0.temperature_min field must be at least -4000.',
                'measurements.0.pressure_max' => 'The measurements.0.pressure_max field must not be greater than 110000.',
            ]);
    });

    it('needs at least one sample', function (): void {
        postJson('/api/v1/measurement', v2Window(['samples' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.samples' => 'The measurements.0.samples field must be at least 1.']);
    });

    it('does not ask a V1 packet for extremes', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value, 'measurements' => [['temperature' => 2134]]])
            ->assertJsonMissingValidationErrors(['measurements.0.temperature_min', 'measurements.0.samples']);
    });
});

describe('station report', function (): void {
    it('is optional as a whole', function (): void {
        postJson('/api/v1/measurement', ['protocol_version' => ProtocolVersion::V1->value])
            ->assertJsonMissingValidationErrors(['station', 'station.firmware', 'station.ssid']);
    });

    it('demands every field once present', function (): void {
        postJson('/api/v1/measurement', ['station' => ['firmware' => '2.1.0']])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'station.reset_reason' => 'The station.reset reason field is required when station is present.',
                'station.uptime' => 'The station.uptime field is required when station is present.',
                'station.heap_free' => 'The station.heap free field is required when station is present.',
                'station.heap_min' => 'The station.heap min field is required when station is present.',
                'station.ssid' => 'The station.ssid field must be present when station is present.',
                'station.ip' => 'The station.ip field must be present when station is present.',
                'station.rssi' => 'The station.rssi field is required when station is present.',
                'station.wifi_network' => 'The station.wifi network field is required when station is present.',
                'station.wifi_switches' => 'The station.wifi switches field is required when station is present.',
                'station.buffered' => 'The station.buffered field is required when station is present.',
                'station.upload_failures' => 'The station.upload failures field is required when station is present.',
            ]);
    });

    it('takes an empty network name and address from a station that is offline', function (): void {
        postJson('/api/v1/measurement', ['station' => ['ssid' => '', 'ip' => '']])
            ->assertJsonMissingValidationErrors(['station.ssid', 'station.ip']);
    });

    it('bounds the signal strength', function (): void {
        postJson('/api/v1/measurement', ['station' => ['rssi' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['station.rssi' => 'The station.rssi field must not be greater than 0.']);
    });

    it('knows two networks only', function (): void {
        postJson('/api/v1/measurement', ['station' => ['wifi_network' => 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['station.wifi_network' => 'The station.wifi network field must not be greater than 1.']);
    });

    // A firmware before 2.2 sends no clock fields, and it keeps uploading through the server upgrade.
    it('does not demand the clock drift', function (): void {
        postJson('/api/v1/measurement', ['station' => ['firmware' => '2.1.0']])
            ->assertJsonMissingValidationErrors(['station.clock_step_ms', 'station.clock_step_over_s', 'station.clock_step_max_ms', 'station.clock_synced_at']);
    });

    it('demands the whole clock set once any of it is present', function (): void {
        postJson('/api/v1/measurement', ['station' => ['clock_synced_at' => 1756998000]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'station.clock_step_ms' => 'The station.clock step ms field is required when station.clock step over s / station.clock step max ms / station.clock synced at is present.',
                'station.clock_step_over_s' => 'The station.clock step over s field is required when station.clock step ms / station.clock step max ms / station.clock synced at is present.',
                'station.clock_step_max_ms' => 'The station.clock step max ms field is required when station.clock step ms / station.clock step over s / station.clock synced at is present.',
            ]);
    });

    it('takes a clock step of either sign, over a positive interval', function (): void {
        postJson('/api/v1/measurement', ['station' => ['clock_step_ms' => -812, 'clock_step_max_ms' => -1204, 'clock_step_over_s' => -1, 'clock_synced_at' => 'yesterday']])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors(['station.clock_step_ms', 'station.clock_step_max_ms'])
            ->assertJsonValidationErrors([
                'station.clock_step_over_s' => 'The station.clock step over s field must be at least 0.',
                'station.clock_synced_at' => 'The station.clock synced at field must be an integer.',
            ]);
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
