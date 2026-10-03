<?php

declare(strict_types=1);

use App\Livewire\Overview;
use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('shows the forecast issued from the newest reading', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->addMinutes(9)->getTimestamp()]);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => now()->getTimestamp(),
        'corrected' => true,
        'data' => [
            forecastHorizon(1, 13.84, 75.7, 0.023),
            forecastHorizon(2, 14.2, 80.0, 0.5),
        ],
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('Next six hours')
        ->assertSee('class="layer graticule"', false)
        // 08:00 UTC is 10:00 in Prague in September.
        ->assertSeeInOrder(['11:00', '13,8', '12,4-15,6', '2 %', 'rain'])
        ->assertSeeInOrder(['12:00', '14,2', '12,8-15,9', '50 %', 'rain'])
        ->assertDontSee('No forecast from the current readings yet')
        // Forecast humidity and pressure stay in the row, off the page.
        ->assertDontSee('1 017,3 hPa')
        ->assertDontSee('76 %');
});

it('keeps a forecast a few missed uploads old', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->subMinutes(39)->getTimestamp()]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertDontSee('No forecast from the current readings yet');
});

it('hides a forecast that no longer starts from the current record', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->subMinutes(41)->getTimestamp()]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('No forecast from the current readings yet')
        ->assertDontSee('8 in 10');
});

it('shows the selected sensor\'s forecast, not another station\'s', function (): void {
    $shown = Sensor::factory()->create(['name' => 'north']);
    $other = Sensor::factory()->create(['name' => 'south']);
    Measurement::factory()->for($shown)->create(['timestamp' => now()->getTimestamp()]);
    Measurement::factory()->for($other)->create(['timestamp' => now()->getTimestamp()]);
    Forecast::factory()->for($other)->create(['issued_at' => now()->getTimestamp()]);

    $this->get(route('overview', ['sensor' => 'north']))
        ->assertOk()
        ->assertSee('No forecast from the current readings yet')
        ->assertDontSee('8 in 10');
});

it('picks up a newer forecast on the next poll', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->getTimestamp(), 'data' => [forecastHorizon(1, 12.0, 80.0, 0.02)]]);
    $overview = Livewire::test(Overview::class)->assertSee('11:00')->assertDontSee('11:10');

    $this->travel(10)->minutes();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->getTimestamp(), 'data' => [forecastHorizon(1, 12.0, 80.0, 0.02)]]);

    $overview->call('$refresh')->assertSee('11:10');
});

it('shows no forecast for a row without hours', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    Forecast::factory()->for($sensor)->create(['issued_at' => now()->getTimestamp(), 'data' => []]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('No forecast from the current readings yet')
        ->assertDontSee('8 in 10');
});
