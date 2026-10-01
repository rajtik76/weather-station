<?php

declare(strict_types=1);

use App\Livewire\Overview;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\NoiseWindow;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('reads the day\'s extremes off the samples rather than the means', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    // Coldest mean 2,10 °C with a sample at 1,05; warmest mean 21,50 with a sample at 24,90.
    Measurement::factory()->for($sensor)->v2()->create([
        'timestamp' => now()->subHours(8)->getTimestamp(),
        'data' => (string) new MeasurementDataV2(
            temperature: 210, humidity: 9000, pressure: 97389,
            temperatureMin: 105, temperatureMax: 300,
            humidityMin: 8850, humidityMax: 9300,
            pressureMin: 97380, pressureMax: 97395,
            samples: 20,
        ),
    ]);
    Measurement::factory()->for($sensor)->v2()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV2(
            temperature: 2150, humidity: 4800, pressure: 97389,
            temperatureMin: 2010, temperatureMax: 2490,
            humidityMin: 4400, humidityMax: 5100,
            pressureMin: 97380, pressureMax: 97395,
            samples: 20,
        ),
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder(['Measure · CH1', '21,5'])
        ->assertSeeInOrder(['Min', '1,1 °C', 'Max', '24,9 °C'])
        ->assertSee('min 1,1 · max 24,9 °C')
        ->assertSeeInOrder(['Humidity', 'min', '44,0', 'max', '93,0']);
});

it('reads the noise into the hero beside the weather', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    foreach ([[8, 4210], [2, 6380], [0, 5562]] as [$hoursAgo, $laeq]) {
        Measurement::factory()->for($sensor)->v3()->create([
            'timestamp' => now()->subHours($hoursAgo)->subMinutes(10)->getTimestamp(),
            'data' => (string) noisyWindow($laeq, $laeq + 200, $laeq - 300, $laeq + 900, 3000),
        ]);
    }

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder(['Noise, LAeq', '55,6', 'dB(A)', 'min', '42,1', 'max', '63,8']);
});

it('marks the noise as the last heard once the newest window has none', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->v3()->create([
        'timestamp' => now()->subHours(2)->getTimestamp(),
        'data' => (string) noisyWindow(5562, 5762, 5262, 6462, 3000),
    ]);
    // The microphone died: the newest window carries the weather alone.
    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 1200, humidity: 6000, pressure: 97000),
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder(['Noise, LAeq', '55,6', 'Missing from the newest window: the figure is the last one read.']);

    expect(Livewire::test(Overview::class)->get('silentChannels'))->toBe(['n']);
});

it('reads the noise as now while the newest window carries it', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    Measurement::factory()->for(Sensor::factory()->create())->v3()->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) noisyWindow(5562, 5762, 5262, 6462, 3000),
    ]);

    $this->get(route('overview'))->assertOk()->assertDontSee('Missing from the newest window');
});

it('reads the light into the hero with the day\'s brightest and darkest sample', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    foreach ([[8, 5, 0, 12], [2, 250_000, 200_000, 610_000], [0, 123_456, 100_000, 150_000]] as [$hoursAgo, $lux, $min, $max]) {
        Measurement::factory()->for($sensor)->v4()->create([
            'timestamp' => now()->subHours($hoursAgo)->subMinutes(10)->getTimestamp(),
            'data' => (string) litWindow($lux, $min, $max),
        ]);
    }

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder(['Light, in the shield', '1 235', 'lx', 'min', '0', 'max', '6 100']);
});

it('leaves the light out of the hero for a day without it', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v3()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    Livewire::test(Overview::class)->assertDontSee('Light, in the shield');
});

it('leaves the noise out of the hero for a day without it', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v2()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('Temperature')
        ->assertDontSee('Noise, LAeq');
});

it('draws the last day under each readout', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Overview::class)->html();

    expect($html)->toContain('last 24 h · 10 min windows')
        ->and(substr_count($html, '<article'))->toBe(3)
        ->and(substr_count($html, 'stroke-width: 1.6px'))->toBe(3);
});

it('says in the noise channel when the microphone hears rain, and nothing otherwise', function (?string $spectrum, string $note): void {
    $bands = match ($spectrum) {
        'rain' => rainyBands(),
        'dry' => array_fill(0, 26, 3000),
        default => null,
    };
    $data = $bands === null
        ? new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389)
        : new MeasurementDataV3(
            temperature: 2150, humidity: 4800, pressure: 97389,
            temperatureMin: 2100, temperatureMax: 2200,
            humidityMin: 4700, humidityMax: 4900,
            pressureMin: 97380, pressureMax: 97395,
            samples: 20,
            noise: new NoiseWindow(seconds: 600, laeq: 5000, lamax: 6000, la10: 5500, la90: 4500, bands: $bands),
        );
    ($bands === null ? Measurement::factory() : Measurement::factory()->v3())
        ->create(['timestamp' => now()->subMinutes(5)->getTimestamp(), 'data' => (string) $data]);

    $overview = Livewire::test(Overview::class);

    $overview->assertSee($note);

    if ($spectrum === null) {
        $overview->assertDontSee('Noise, LAeq');
    }
})->with([
    // Drips loud at 8 kHz with the shield ringing at 1 kHz, as RainDetector hears rain.
    'rain' => ['rain', 'Rain heard now.'],
    'dry' => ['dry', 'No rain heard.'],
    'no microphone' => [null, 'Temperature'],
]);

it('keeps quiet about rain once the station has gone silent', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    // Rain heard two hours ago, then nothing: the shower is not the weather now.
    Measurement::factory()->v3()->for($sensor)->create([
        'timestamp' => now()->subHours(2)->getTimestamp(),
        'data' => (string) new MeasurementDataV3(
            temperature: 2150, humidity: 4800, pressure: 97389,
            temperatureMin: 2100, temperatureMax: 2200,
            humidityMin: 4700, humidityMax: 4900,
            pressureMin: 97380, pressureMax: 97395,
            samples: 20,
            noise: new NoiseWindow(seconds: 600, laeq: 5000, lamax: 6000, la10: 5500, la90: 4500, bands: rainyBands()),
        ),
    ]);

    Livewire::test(Overview::class)
        ->assertSee('STOP')
        ->assertSee('Noise, LAeq')
        ->assertDontSee('Rain heard now.')
        ->assertDontSee('No rain heard.');
});
