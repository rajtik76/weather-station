<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Sensor;
use App\Models\StationEvent;
use Database\Seeders\MeasurementSeeder;
use Database\Seeders\StationEventSeeder;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\seed;

it('seeds events every seeded station marks in its default week', function (string $name): void {
    seed([MeasurementSeeder::class, StationEventSeeder::class]);

    $sensor = Sensor::query()->where('name', $name)->sole();
    $newest = $sensor->measurements()->max('timestamp');
    $times = StationEvent::query()->whereBelongsTo($sensor)->pluck('occurred_at')->sort()->values();

    expect($times)->toHaveCount(6)
        ->and($times->first())->toBeGreaterThan($newest - 7 * 86_400)
        ->and($times->last())->toBeLessThanOrEqual($newest)
        // Two side by side, so the chart shows how neighbouring icons sit.
        ->and($times->sliding(2)->contains(fn (Collection $pair): bool => $pair->last() - $pair->first() <= 3600))->toBeTrue();

    /** @var Charts $charts */
    $charts = Livewire::withQueryParams(['sensor' => $sensor->slug])
        ->test(Charts::class)
        ->instance();

    expect($charts->stationEvents)->toHaveCount(6);
})->with(['sensor-001', 'sensor-002']);
