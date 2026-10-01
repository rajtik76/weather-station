<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\LightWindow;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('keeps the light strip over a window before the sensor had light', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->v3()->create(['timestamp' => now()->subHours(3)->getTimestamp()]);
    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => now()->subMinutes(10)->getTimestamp(), 'data' => (string) litWindow(100_000, 80_000, 120_000)]);

    $html = Livewire::withQueryParams([
        'from' => now()->subHours(4)->getTimestamp(),
        'to' => now()->subHours(2)->getTimestamp(),
    ])->test(Charts::class)->html();

    expect(lightBuckets($html))->toBe([])
        ->and($html)->toContain('Light in the shield, lx');
});

it('draws no light strip for a sensor that never had light', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v3()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Charts::class)->html();

    expect(lightBuckets($html))->toBe([])
        ->and($html)->not->toContain('Light in the shield, lx');
});

it('shows the light strip once a sensor without it starts sending light', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->v3()->create(['timestamp' => now()->subMinutes(20)->getTimestamp()]);

    $charts = Livewire::test(Charts::class);

    expect($charts->html())->not->toContain('Light in the shield, lx');

    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => now()->subMinutes(10)->getTimestamp(), 'data' => (string) litWindow(100_000, 80_000, 120_000)]);

    expect($charts->call('$refresh')->html())->toContain('Light in the shield, lx');
});

it('averages the light in a bucket with the extremes of its windows', function (): void {
    $start = Date::parse('2026-03-15 10:00:00', 'UTC');
    $this->travelTo($start->copy()->addHours(2));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => $start->getTimestamp(), 'data' => (string) litWindow(100_000, 80_000, 120_000)]);
    Measurement::factory()->for($sensor)->v4()->create(['timestamp' => $start->getTimestamp() + 600, 'data' => (string) litWindow(300_000, 250_000, 400_000)]);
    // A window whose VEML7700 gave nothing does not pull the mean down.
    Measurement::factory()->for($sensor)->v4()->create([
        'timestamp' => $start->getTimestamp() + 1200,
        'data' => json_encode(array_diff_key(litWindow(0, 0, 0)->jsonSerialize(), array_flip(LightWindow::FIELDS))),
    ]);

    $html = Livewire::test(Charts::class)->html();
    $row = lightBuckets($html)[$start->getTimestamp()];

    expect(array_slice($row, 2))->toEqual([2000.0, 800.0, 4000.0])
        ->and($html)->toContain('Light in the shield, lx');
});
