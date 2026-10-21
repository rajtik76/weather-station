<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Enums\RainReportSource;
use App\Models\Measurement;
use App\Models\RainReport;
use App\Models\Sensor;
use App\ValueObject\LocalTime;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\call;
use function Pest\Laravel\freezeTime;

const RAIN_CHANNEL = '1400000000000000002';

/**
 * @param  array<string, mixed>  $payload
 * @param  ?non-empty-string  $signingKeyPair
 * @return TestResponse<Response>
 */
function discordInteraction(array $payload, ?string $signingKeyPair = null, ?int $sentAt = null): TestResponse
{
    $keyPair = sodium_crypto_sign_keypair();
    config([
        'services.discord.public_key' => bin2hex(sodium_crypto_sign_publickey($keyPair)),
        'services.discord.channel_id' => RAIN_CHANNEL,
    ]);
    $body = json_encode(['channel_id' => RAIN_CHANNEL, ...$payload], JSON_THROW_ON_ERROR);
    $timestamp = (string) ($sentAt ?? now()->getTimestamp());
    $signature = sodium_crypto_sign_detached($timestamp.$body, sodium_crypto_sign_secretkey($signingKeyPair ?? $keyPair));

    return call('POST', '/api/discord/interactions', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE_ED25519' => bin2hex($signature),
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
    ], content: $body);
}

it('refuses an interaction not signed by the application', function (): void {
    discordInteraction(['type' => 1], signingKeyPair: sodium_crypto_sign_keypair())
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid request signature.']);
});

it('refuses a replayed interaction', function (): void {
    discordInteraction(['type' => 2, 'data' => ['name' => 'rain']], sentAt: now()->getTimestamp() - 301)
        ->assertUnauthorized();

    assertDatabaseCount('rain_reports', 0);
});

it('refuses an interaction without a signature', function (): void {
    config(['services.discord.public_key' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);

    call('POST', '/api/discord/interactions', server: ['CONTENT_TYPE' => 'application/json'], content: '{"type":1}')
        ->assertUnauthorized();
});

it('answers Discord\'s ping', function (): void {
    discordInteraction(['type' => 1])->assertOk()->assertExactJson(['type' => 1]);
});

it('logs the owner\'s report next to what the detector hears', function (string $command, bool $raining, string $logged): void {
    freezeTime();
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->getTimestamp() - 60,
        'protocol_version' => ProtocolVersion::V3,
        'data' => (string) spectrumWindow(high: 42.0, ring: 0.0),
    ]);

    discordInteraction(['type' => 2, 'data' => ['name' => $command]])
        ->assertOk()
        ->assertExactJson(['type' => 4, 'data' => [
            'content' => $logged.LocalTime::of(now()->getTimestamp())->stamp().'. The detector hears none.',
        ]]);

    $report = RainReport::query()->sole();
    expect($report->sensor_id)->toBe($sensor->id)
        ->and($report->source)->toBe(RainReportSource::Observer)
        ->and($report->timestamp)->toBe(now()->getTimestamp())
        ->and($report->detected)->toBeFalse()
        ->and($report->raining)->toBe($raining);
})->with([
    'rain' => ['rain', true, 'Logged: raining at '],
    'dry' => ['dry', false, 'Logged: not raining at '],
]);

it('logs the owner\'s report without a detector reading when the station is silent', function (): void {
    freezeTime();
    Measurement::factory()->for(Sensor::factory())->create([
        'timestamp' => now()->getTimestamp() - 1860,
        'protocol_version' => ProtocolVersion::V3,
        'data' => (string) spectrumWindow(high: 50.0, ring: 3.0),
    ]);

    discordInteraction(['type' => 2, 'data' => ['name' => 'rain']])
        ->assertOk()
        ->assertJsonPath('data.content', 'Logged: raining at '.LocalTime::of(now()->getTimestamp())->stamp().'. The detector has no fresh window.');

    expect(RainReport::query()->sole()->detected)->toBeNull();
});

it('records the answer and marks it in the message, keeping the buttons for a change of mind', function (string $button, bool $raining, string $answer): void {
    $report = RainReport::factory()->create(['timestamp' => 1792606140, 'detected' => true]);

    $response = discordInteraction(['type' => 3, 'data' => ['custom_id' => "rain-report:{$report->id}:{$button}"]])->assertOk();

    expect($report->refresh()->raining)->toBe($raining);
    $response->assertJsonPath('type', 7)
        ->assertJsonPath('data.content', '🌧️ Rain heard at '.LocalTime::of(1792606140)->stamp().". Is it raining?\nAnswer: ".$answer)
        ->assertJsonPath('data.components.0.components.0.custom_id', "rain-report:{$report->id}:yes")
        ->assertJsonPath('data.components.0.components.1.custom_id', "rain-report:{$report->id}:no");
})->with([
    'confirmed' => ['yes', true, '✅ raining'],
    'refuted' => ['no', false, '❌ not raining'],
]);

it('tells the owner when the answered report is gone', function (): void {
    discordInteraction(['type' => 3, 'data' => ['custom_id' => 'rain-report:999:yes']])
        ->assertOk()
        ->assertExactJson(['type' => 4, 'data' => ['content' => 'This report no longer exists.']]);
});

it('rejects an interaction it does not handle', function (array $payload): void {
    discordInteraction($payload)->assertBadRequest();

    assertDatabaseCount('rain_reports', 0);
})->with([
    'unknown command' => [['type' => 2, 'data' => ['name' => 'snow']]],
    'autocomplete' => [['type' => 4]],
]);

it('takes reports only from the rain channel', function (array $payload): void {
    $report = RainReport::factory()->create(['detected' => true]);
    $payload = str_replace('{id}', (string) $report->id, json_encode($payload, JSON_THROW_ON_ERROR));

    discordInteraction([...json_decode($payload, true), 'channel_id' => '1400000000000000009'])
        ->assertOk()
        ->assertExactJson(['type' => 4, 'data' => ['content' => 'Rain reports are taken only in the rain channel.', 'flags' => 64]]);

    expect(RainReport::query()->sole()->raining)->toBeNull();
})->with([
    'command' => [['type' => 2, 'data' => ['name' => 'rain']]],
    'button' => [['type' => 3, 'data' => ['custom_id' => 'rain-report:{id}:no']]],
]);

it('is not throttled with the sensor uploads', function (): void {
    foreach (range(1, 11) as $attempt) {
        discordInteraction(['type' => 1])->assertOk();
    }
});
