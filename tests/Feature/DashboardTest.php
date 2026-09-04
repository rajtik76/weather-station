<?php

declare(strict_types=1);

use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;

it('renders the readings stored in the database', function (): void {
    $at = now()->subHour();

    Measurement::factory()->create([
        'sensor_name' => 'bme280',
        'timestamp' => $at->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 101300),
    ]);

    $this->get('/')
        ->assertOk()
        // Raw units are converted for display, keeping the sensor's two decimals:
        // 2150 -> 21,50 °C, 4800 -> 48,00 %, 101300 Pa -> 1013,0 hPa.
        ->assertSee('21,50')
        ->assertSee('48,00')
        ->assertSee('1 013,0')
        ->assertDontSee('Waiting for the first reading');
});

it('shows an empty state when nothing has been recorded', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Waiting for the first reading');
});

it('ignores readings older than the chart window', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subDays(40)->getTimestamp(),
    ]);

    $this->get('/')->assertSee('Waiting for the first reading');
});
