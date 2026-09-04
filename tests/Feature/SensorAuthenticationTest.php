<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Models\Measurement;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\postJson;

/**
 * @return array<string, mixed>
 */
function measurementPayload(): array
{
    return [
        'protocol_version' => ProtocolVersion::V1->value,
        'sensor_name' => 'test-sensor',
        'measurements' => [
            ['timestamp' => now()->timestamp, 'temperature' => 200, 'humidity' => 40, 'pressure' => 100000],
        ],
    ];
}

it('rejects a request without a bearer token', function (): void {
    $this->flushHeaders();

    postJson('api/v1/measurement', measurementPayload())
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);

    assertDatabaseCount(Measurement::class, 0);
});

it('rejects a request with a wrong bearer token', function (): void {
    $this->flushHeaders()->withHeader('Authorization', 'Bearer wrong-token');

    postJson('api/v1/measurement', measurementPayload())->assertUnauthorized();

    assertDatabaseCount(Measurement::class, 0);
});

it('rejects a request when no token is configured', function (): void {
    config()->set('sensor.api_token', null);

    postJson('api/v1/measurement', measurementPayload())->assertUnauthorized();
});

it('accepts a request with the configured bearer token', function (): void {
    postJson('api/v1/measurement', measurementPayload())->assertCreated();

    assertDatabaseCount(Measurement::class, 1);
});

it('throttles the api routes', function (): void {
    $this->flushHeaders();

    foreach (range(1, 10) as $ignored) {
        postJson('api/v1/measurement', [])->assertUnauthorized();
    }

    postJson('api/v1/measurement', [])->assertStatus(429);
});
