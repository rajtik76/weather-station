<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Livewire\Dashboard;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\NoiseWindow;
use Database\Seeders\MeasurementSeeder;
use Livewire\Livewire;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

it('seeds the first station\'s last days with noise the API accepts', function (): void {
    seed(MeasurementSeeder::class);

    $windows = Measurement::query()
        ->whereBelongsTo(Sensor::query()->where('name', 'sensor-001')->sole())
        ->where('protocol_version', ProtocolVersion::V3)
        ->orderBy('timestamp')
        ->get();

    // Three days of ten-minute windows, every one with noise.
    expect($windows)->toHaveCount(3 * 144)
        ->and($windows->every(fn (Measurement $window): bool => $window->data instanceof MeasurementDataV3 && $window->data->noise instanceof NoiseWindow))->toBeTrue();

    // Sent back through the endpoint, the seeded windows pass the real validation.
    postJson('api/v1/measurement', [
        'sensor_name' => 'seed-check',
        'protocol_version' => ProtocolVersion::V3->value,
        'measurements' => $windows->map(fn (Measurement $window): array => [
            'timestamp' => $window->timestamp,
            ...$window->data->jsonSerialize(),
        ])->all(),
    ])->assertCreated();
});

it('draws the noise strips for the seeded station with a microphone only', function (): void {
    seed(MeasurementSeeder::class);

    Livewire::test(Dashboard::class)->assertSee('Noise spectrum');
    Livewire::withQueryParams(['sensor' => 'sensor-002'])->test(Dashboard::class)->assertDontSee('Noise spectrum');
});

it('seeds showers the waterfall marks, only where there is a microphone', function (): void {
    seed(MeasurementSeeder::class);

    expect(Livewire::test(Dashboard::class)->get('rainSlots'))->not->toBeEmpty()
        ->and(Livewire::withQueryParams(['sensor' => 'sensor-002'])->test(Dashboard::class)->get('rainSlots'))->toBeEmpty();
});
