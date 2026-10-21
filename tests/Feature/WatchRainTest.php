<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Enums\RainReportSource;
use App\Models\Measurement;
use App\Models\RainReport;
use App\Models\Sensor;
use App\Queries\Discord;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\freezeTime;

const DISCORD_CHANNEL = '1400000000000000001';

function configureDiscord(): string
{
    config([
        'services.discord.application_id' => '1400000000000000000',
        'services.discord.bot_token' => 'testing-bot-token',
        'services.discord.channel_id' => DISCORD_CHANNEL,
    ]);

    return Discord::BASE_URL.'/channels/'.DISCORD_CHANNEL.'/messages';
}

function heardWindow(Sensor $sensor, int $timestamp, bool $rainy): void
{
    Measurement::factory()->for($sensor)->create([
        'timestamp' => $timestamp,
        'protocol_version' => ProtocolVersion::V3,
        'data' => (string) ($rainy ? spectrumWindow(high: 50.0, ring: 3.0) : spectrumWindow(high: 30.0, ring: 0.0)),
    ]);
}

it('asks on Discord when the detector starts hearing rain', function (): void {
    freezeTime();
    Http::preventStrayRequests();
    Http::fake([configureDiscord() => Http::response(['id' => '1500000000000000001'])]);
    $sensor = Sensor::factory()->create();
    $window = now()->getTimestamp() - 60;
    heardWindow($sensor, $window, rainy: true);

    expect(Artisan::call('rain:watch'))->toBe(0);

    $report = RainReport::query()->sole();
    expect($report->source)->toBe(RainReportSource::Detector)
        ->and($report->timestamp)->toBe($window)
        ->and($report->detected)->toBeTrue()
        ->and($report->raining)->toBeNull()
        ->and($report->discord_message_id)->toBe('1500000000000000001');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bot testing-bot-token')
        && str_starts_with($request['content'], '🌧️ Rain heard at')
        && $request['components'][0]['components'][0]['custom_id'] === "rain-report:{$report->id}:yes"
        && $request['components'][0]['components'][1]['custom_id'] === "rain-report:{$report->id}:no");
});

it('asks again when the detector stops hearing rain', function (): void {
    freezeTime();
    Http::preventStrayRequests();
    Http::fake([configureDiscord() => Http::response(['id' => '1500000000000000002'])]);
    $sensor = Sensor::factory()->create();
    RainReport::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp() - 1860, 'detected' => true]);

    foreach ([1260, 660, 60] as $secondsAgo) {
        heardWindow($sensor, now()->getTimestamp() - $secondsAgo, rainy: false);
    }

    expect(Artisan::call('rain:watch'))->toBe(0);

    assertDatabaseHas('rain_reports', ['sensor_id' => $sensor->id, 'detected' => false, 'timestamp' => now()->getTimestamp() - 60, 'discord_message_id' => '1500000000000000002']);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request['content'], '🌤️ Rain no longer heard at'));
});

it('waits for half an hour without rain before saying it stopped', function (array $windows): void {
    freezeTime();
    Http::preventStrayRequests();
    configureDiscord();
    $sensor = Sensor::factory()->create();
    RainReport::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp() - 1860, 'detected' => true]);

    foreach ($windows as $secondsAgo => $rainy) {
        heardWindow($sensor, now()->getTimestamp() - $secondsAgo, $rainy);
    }

    expect(Artisan::call('rain:watch'))->toBe(0);

    Http::assertNothingSent();
    assertDatabaseCount('rain_reports', 1);
})->with([
    'one dry window so far' => [[60 => false]],
    'rain twenty minutes ago' => [[1260 => true, 660 => false, 60 => false]],
    'rain ten minutes ago' => [[1260 => false, 660 => true, 60 => false]],
]);

it('stays quiet while the detector keeps its mind', function (?bool $lastDetected, bool $rainy): void {
    freezeTime();
    Http::preventStrayRequests();
    configureDiscord();
    $sensor = Sensor::factory()->create();

    if ($lastDetected !== null) {
        RainReport::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp() - 660, 'detected' => $lastDetected]);
    }

    heardWindow($sensor, now()->getTimestamp() - 60, $rainy);

    expect(Artisan::call('rain:watch'))->toBe(0);

    Http::assertNothingSent();
    assertDatabaseCount('rain_reports', $lastDetected === null ? 0 : 1);
})->with([
    'dry from the start' => [null, false],
    'still raining' => [true, true],
    'still dry' => [false, false],
]);

it('reads a stale window as no answer, not as a dry sky', function (): void {
    freezeTime();
    Http::preventStrayRequests();
    configureDiscord();
    $sensor = Sensor::factory()->create();
    RainReport::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp() - 3600, 'detected' => true]);
    heardWindow($sensor, now()->getTimestamp() - 1860, rainy: false);

    expect(Artisan::call('rain:watch'))->toBe(0);

    Http::assertNothingSent();
    assertDatabaseCount('rain_reports', 1);
});

it('asks again on the next run when Discord refused the message', function (): void {
    freezeTime();
    Http::preventStrayRequests();
    Http::fake([configureDiscord() => Http::sequence()
        ->push(['message' => 'Missing Access'], 403)
        ->push(['id' => '1500000000000000003'])]);
    heardWindow(Sensor::factory()->create(), now()->getTimestamp() - 60, rainy: true);

    expect(Artisan::call('rain:watch'))->toBe(1)
        ->and(RainReport::query()->sole()->discord_message_id)->toBeNull();

    expect(Artisan::call('rain:watch'))->toBe(0)
        ->and(RainReport::query()->sole()->discord_message_id)->toBe('1500000000000000003');
    Http::assertSentCount(2);
});

it('still asks for the other stations when one fails', function (): void {
    freezeTime();
    Http::preventStrayRequests();
    Http::fake([configureDiscord() => Http::sequence()
        ->push(['message' => 'Service Unavailable'], 503)
        ->push(['id' => '1500000000000000004'])]);
    $failing = Sensor::factory()->create();
    $answered = Sensor::factory()->create();
    heardWindow($failing, now()->getTimestamp() - 60, rainy: true);
    heardWindow($answered, now()->getTimestamp() - 60, rainy: true);

    expect(Artisan::call('rain:watch'))->toBe(1);

    assertDatabaseHas('rain_reports', ['sensor_id' => $failing->id, 'discord_message_id' => null]);
    assertDatabaseHas('rain_reports', ['sensor_id' => $answered->id, 'discord_message_id' => '1500000000000000004']);
});

it('does nothing until Discord is configured', function (): void {
    freezeTime();
    Http::preventStrayRequests();
    heardWindow(Sensor::factory()->create(), now()->getTimestamp() - 60, rainy: true);

    expect(Artisan::call('rain:watch'))->toBe(0)
        ->and(Artisan::output())->toContain('Discord is not configured.');

    assertDatabaseCount('rain_reports', 0);
});

it('registers the rain and dry slash commands', function (): void {
    Http::preventStrayRequests();
    configureDiscord();
    Http::fake([Discord::BASE_URL.'/applications/1400000000000000000/commands' => Http::response([])]);

    expect(Artisan::call('discord:register-commands'))->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && array_column($request->data(), 'name') === ['rain', 'dry']);
});
