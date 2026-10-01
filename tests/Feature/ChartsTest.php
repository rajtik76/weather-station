<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;
use Livewire\Livewire;

it('draws the strips in the instrument look without polling', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subHour()->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $html = $this->get(route('charts'))
        ->assertOk()
        ->assertSee('class="instrument', false)
        ->assertSee('data-strip="th"', false)
        ->assertSee('data-strip="p"', false)
        ->assertSee('data-navigator', false)
        ->assertSeeInOrder(['CH1', 'Temperature, °C', 'CH2', 'Humidity, %'])
        ->assertDontSee('Nothing in this range')
        ->getContent();

    // Only the overview refreshes itself; a chosen window must stay put.
    expect($html)->not->toContain('wire:poll')
        ->and($html)->toMatch('/data-chart-rows="[^"]*21\\.5/');
});

it('marks the charts page as the current one in the navigation', function (): void {
    expect($this->get(route('charts'))->assertOk()->getContent())
        ->toMatch('#href="[^"]*/charts"\s+aria-current="page"#')
        ->not->toMatch('#href="[^"]*/overview"\s+aria-current="page"#');
});

it('switches a weather line off from its label', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subHour()->getTimestamp()]);

    Livewire::test(Charts::class)
        ->assertSeeHtml('aria-pressed="false"')
        ->call('toggleChannel', 'h')
        ->assertSet('channels.h', false);
});

it('prints the newest windows and the board\'s report without its network identity', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(5)->getTimestamp()]);
    StationReport::factory()->for($sensor)->create([
        'data' => [...StationReport::factory()->raw()['data'], 'firmware' => '4.0.2', 'ssid' => 'home', 'ip' => '192.168.0.42'],
    ]);

    $this->get(route('charts'))
        ->assertOk()
        ->assertSeeInOrder(['Last 1 windows', 'CH1', 'CH2', 'CH3'])
        ->assertSeeInOrder(['firmware', '4.0.2'])
        ->assertDontSee('192.168.0.42');
});
