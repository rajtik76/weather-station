<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Livewire\Overview;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('reports when the station last transmitted', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subMinutes(12)->getTimestamp()]);

    $this->get(route('overview'))
        ->assertOk()
        // 11:48 UTC is 12:48 in Prague, which is still on CET in mid-March.
        ->assertSeeInOrder(['RUN', '15.3.2026 12:48']);
});

it('still reports the last measurement when the window holds nothing', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

    Livewire::test(Overview::class)
        ->assertSee('12.3.2026 13:00')
        ->assertSee('STOP')
        ->assertSee('Measure · CH1')
        ->assertDontSee('Waiting for the first reading');
});

it('calls the station silent after three missed slots', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(20)->getTimestamp()]);
    Livewire::test(Overview::class)
        ->assertSee('RUN')
        ->assertDontSee('STOP');

    Measurement::query()->delete();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(40)->getTimestamp()]);
    Livewire::test(Overview::class)
        ->assertSee('STOP')
        ->assertDontSee('RUN');
});

it('turns the station silent on a poll once the uploads stop', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    Measurement::factory()->create(['timestamp' => now()->subMinute()->getTimestamp()]);

    $overview = Livewire::test(Overview::class)->assertSee('RUN');

    $this->travel(40)->minutes();

    $overview->call('$refresh')
        ->assertSee('STOP')
        ->assertDontSee('RUN');
});

it('names each sensor in the picker', function (): void {
    Sensor::factory()->create(['name' => 'bme280-north']);
    Sensor::factory()->create(['name' => 'sht41-south']);

    Livewire::test(Overview::class)
        ->assertSeeHtmlInOrder(['aria-label="Choose a sensor"', '>bme280-north<', '>sht41-south<']);
});

it('reports when no sensor has registered yet', function (): void {
    Livewire::test(Overview::class)
        ->assertSee('Waiting for the first reading')
        ->assertDontSee('RUN')
        ->assertDontSee('STOP')
        ->assertSet('sensor', null);
});

it('offers a picker only once there are two sensors', function (): void {
    Sensor::factory()->create();

    Livewire::test(Overview::class)->assertDontSee('Choose a sensor');

    Sensor::factory()->create();

    Livewire::test(Overview::class)->assertSee('Choose a sensor');
});

it('opens on the first registered sensor and switches on request', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $first = Sensor::factory()->create(['name' => 'first']);
    $second = Sensor::factory()->create(['name' => 'second']);

    Measurement::factory()->for($first)->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);
    Measurement::factory()->for($second)->create([
        'timestamp' => now()->subMinutes(30)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 1050, humidity: 8800, pressure: 96389),
    ]);

    $component = Livewire::test(Overview::class)
        ->assertSet('sensor', 'first')
        ->assertSee('21,5')
        ->assertDontSee('10,5')
        ->assertSee('15.3.2026 12:50')
        ->assertDontSee('15.3.2026 12:30');

    $component->set('sensor', 'second')
        ->assertSet('sensor', 'second')
        ->assertSee('10,5')
        ->assertDontSee('21,5')
        ->assertSee('15.3.2026 12:30')
        ->assertDontSee('15.3.2026 12:50');
});

it('carries the chosen sensor into the other pages', function (): void {
    Sensor::factory()->create(['name' => 'first']);
    $second = Sensor::factory()->create(['name' => 'second']);

    Livewire::test(Charts::class)
        ->set('sensor', $second->slug)
        ->assertSeeHtml('href="'.route('forecast', ['sensor' => $second->slug]).'"');
});

it('falls back to the first sensor when the link names one that is gone', function (): void {
    Sensor::factory()->create(['name' => 'the-only-one']);

    Livewire::withQueryParams(['sensor' => 'the-other-one']);

    Livewire::test(Overview::class)
        ->assertSet('sensor', 'the-only-one');
});

it('hands the map the station area to draw', function (): void {
    Livewire::test(Overview::class)
        ->assertSee('data-station-map', escape: false)
        ->assertSee('data-lat="49.733242"', escape: false)
        ->assertSee('data-lng="13.399911"', escape: false)
        ->assertSee('data-radius="800"', escape: false);
});
