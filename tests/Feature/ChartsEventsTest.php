<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Sensor;
use App\Models\StationEvent;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('marks station events on the charts in Czech local time', function (): void {
    // 10:00 UTC in July is 12:00 in Prague (CEST, UTC+2).
    StationEvent::factory()->create([
        'occurred_at' => Date::parse('2026-07-15 10:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
        'color' => '#71717a',
    ]);

    $html = Livewire::test(Charts::class)->html();

    // Shifted like the readings, so the mark lands where they do.
    expect(chartEvents($html))->toBe([
        [1784116800000, 'Radiation shield fitted', '#71717a'],
    ]);
});

it('leaves the colour to the chart when the event was entered without one', function (): void {
    StationEvent::factory()->create([
        'occurred_at' => Date::parse('2026-07-15 10:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
        'color' => null,
    ]);

    expect(chartEvents(Livewire::test(Charts::class)->html()))->toBe([
        [1784116800000, 'Radiation shield fitted', null],
    ]);
});

it('marks events in the order they happened whatever order they were entered', function (): void {
    $sensor = Sensor::factory()->create();

    StationEvent::factory()->for($sensor)->create([
        'occurred_at' => Date::parse('2026-08-01 08:00:00', 'UTC'),
        'title' => 'Moved to the south wall',
    ]);
    StationEvent::factory()->for($sensor)->create([
        'occurred_at' => Date::parse('2026-06-01 08:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
    ]);

    $html = Livewire::test(Charts::class)->html();

    expect(array_column(chartEvents($html), 1))
        ->toBe(['Radiation shield fitted', 'Moved to the south wall']);
});

it('hands the charts no events when none have been recorded', function (): void {
    expect(chartEvents(Livewire::test(Charts::class)->html()))->toBe([]);
});

it('marks only the selected sensor\'s events', function (): void {
    $shown = Sensor::factory()->create();
    $other = Sensor::factory()->create();

    StationEvent::factory()->for($shown)->create([
        'occurred_at' => Date::parse('2026-07-15 10:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
    ]);
    StationEvent::factory()->for($other)->create([
        'occurred_at' => Date::parse('2026-07-16 10:00:00', 'UTC'),
        'title' => 'Moved to the balcony',
    ]);

    $component = Livewire::test(Charts::class);

    expect(array_column(chartEvents($component->html()), 1))->toBe(['Radiation shield fitted']);

    $component->set('sensor', $other->slug);

    expect(array_column(chartEvents($component->html()), 1))->toBe(['Moved to the balcony']);
});
