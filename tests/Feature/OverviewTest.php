<?php

declare(strict_types=1);

use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;

it('shows the current readings and polls for new ones', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('wire:poll.60s', false)
        ->assertSeeInOrder(['Measure · CH1', '21,50'])
        ->assertSeeInOrder(['Humidity', '48,00'])
        ->assertSeeInOrder(['Pressure, MSL', '1 013,48'])
        ->assertDontSee('Waiting for the first reading');
});

it('waits for the first reading on an empty station', function (): void {
    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('Waiting for the first reading');
});

it('prints the board\'s report without its network identity', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    StationReport::factory()->for($sensor)->create([
        'data' => [...StationReport::factory()->raw()['data'], 'firmware' => '4.0.2', 'rssi' => -64, 'ssid' => 'home', 'ip' => '192.168.0.42'],
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder(['ACQ RUN', 'RSSI', '−64 dBm', 'fw', '4.0.2'])
        ->assertDontSee('192.168.0.42')
        ->assertDontSee('>home<', false);
});
