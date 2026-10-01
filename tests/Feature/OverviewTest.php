<?php

declare(strict_types=1);

use App\Models\Forecast;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;

/**
 * @return array{hours: int, temperature: array{low: float, mid: float, high: float}, humidity: array{low: float, mid: float, high: float}, pressure: array{low: float, mid: float, high: float}, rain_probability: float}
 */
function overviewHorizon(int $hours, float $temperature, float $rain): array
{
    return [
        'hours' => $hours,
        'temperature' => ['low' => $temperature - 1.5, 'mid' => $temperature, 'high' => $temperature + 1.5],
        'humidity' => ['low' => 60.0, 'mid' => 65.0, 'high' => 70.0],
        'pressure' => ['low' => 976.0, 'mid' => 976.5, 'high' => 977.0],
        'rain_probability' => $rain,
    ];
}

it('shows the current readings and polls for new ones', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('wire:poll.60s', false)
        ->assertSeeInOrder(['Measure · CH1', '21,5'])
        ->assertSeeInOrder(['Humidity', '48,0'])
        // 97 389 Pa at 345 m and 21,50 °C reduces to 1013,5 hPa.
        ->assertSeeInOrder(['Pressure, MSL', '1 013,5'])
        ->assertDontSee('Waiting for the first reading');
});

it('waits for the first reading on an empty station', function (): void {
    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('Waiting for the first reading')
        ->assertSee('No forecast from the current readings yet');
});

it('draws the next six hours with their range and rain chance', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 1300, humidity: 7000, pressure: 97389),
    ]);
    Forecast::factory()->for($sensor)->create([
        'issued_at' => now()->getTimestamp(),
        'data' => [overviewHorizon(1, 13.8, 0.04), overviewHorizon(2, 14.2, 0.5)],
    ]);

    $this->get(route('overview'))
        ->assertOk()
        // 08:00 UTC is 10:00 in Prague in September.
        ->assertSeeInOrder(['11:00', '13,8', '12,3-15,3', '4 %', 'rain'])
        ->assertSeeInOrder(['12:00', '14,2', '12,7-15,7', '50 %', 'rain'])
        ->assertDontSee('No forecast from the current readings yet');
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
