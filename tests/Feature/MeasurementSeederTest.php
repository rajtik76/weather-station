<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\CarriesNoise;
use App\ValueObject\LightWindow;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\NoiseWindow;
use Database\Seeders\MeasurementSeeder;
use Livewire\Livewire;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

it('seeds the first station\'s last days with noise the API accepts', function (): void {
    seed(MeasurementSeeder::class);

    $windows = Measurement::query()
        ->whereBelongsTo(Sensor::query()->where('name', 'sensor-001')->sole())
        ->whereIn('protocol_version', [ProtocolVersion::V3, ProtocolVersion::V4])
        ->orderBy('timestamp')
        ->get();

    expect($windows)->toHaveCount(3 * 144)
        ->and($windows->every(fn (Measurement $window): bool => $window->data instanceof CarriesNoise && $window->data->noise instanceof NoiseWindow))->toBeTrue();

    foreach ($windows->groupBy(fn (Measurement $window): int => $window->data->protocolVersion->value) as $version => $batch) {
        foreach ($batch->chunk(500) as $chunk) {
            postJson('api/v1/measurement', [
                'sensor_name' => 'seed-check',
                'protocol_version' => $version,
                'measurements' => $chunk->map(fn (Measurement $window): array => [
                    'timestamp' => $window->timestamp,
                    ...$window->data->jsonSerialize(),
                ])->values()->all(),
            ])->assertCreated();
        }
    }
});

it('seeds the first station\'s last day with light that follows the sun', function (): void {
    seed(MeasurementSeeder::class);

    $windows = Measurement::query()
        ->whereBelongsTo(Sensor::query()->where('name', 'sensor-001')->sole())
        ->where('protocol_version', ProtocolVersion::V4)
        ->get();

    $lux = $windows->map(fn (Measurement $window): int => $window->data instanceof MeasurementDataV4 && $window->data->light instanceof LightWindow
        ? $window->data->light->illuminance
        : -1);

    expect($windows)->toHaveCount(144)
        ->and($lux->min())->toBeGreaterThanOrEqual(0)->toBeLessThan(100)
        ->and($lux->max())->toBeGreaterThan(100_000);
});

it('draws the light strip only for the station that sends it', function (): void {
    seed(MeasurementSeeder::class);

    Livewire::test(Charts::class)->assertSee('Light in the shield, lx, log scale');
    Livewire::withQueryParams(['sensor' => 'sensor-002'])->test(Charts::class)->assertDontSee('Light in the shield, lx, log scale');
});

it('draws the noise strips for the seeded station with a microphone only', function (): void {
    seed(MeasurementSeeder::class);

    Livewire::test(Charts::class)->assertSee('Noise spectrum');
    Livewire::withQueryParams(['sensor' => 'sensor-002'])->test(Charts::class)->assertDontSee('Noise spectrum');
});

it('seeds showers the waterfall marks, only where there is a microphone', function (): void {
    seed(MeasurementSeeder::class);

    expect(Livewire::test(Charts::class)->get('rainSlots'))->not->toBeEmpty()
        ->and(Livewire::withQueryParams(['sensor' => 'sensor-002'])->test(Charts::class)->get('rainSlots'))->toBeEmpty();
});
