<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Livewire\Overview;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\LightWindow;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\MeasurementDataV4;
use App\ValueObject\NoiseWindow;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\Constraints\SeeInOrder;
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
        ->assertSeeInOrder(['Measure · CH1', '21,50'])
        ->assertSeeInOrder(['Min', '1,05 °C', 'Max', '24,90 °C'])
        ->assertSee('min 1,05 · max 24,90 °C')
        ->assertSeeInOrder(['Humidity', 'min', '44,00', 'max', '93,00']);
});

/**
 * @return array{hourAgo: MeasurementDataV2, now: MeasurementDataV2}
 */
function hundredthsDay(): array
{
    return [
        'hourAgo' => new MeasurementDataV2(
            temperature: 1234, humidity: 9012, pressure: 97389,
            temperatureMin: 1105, temperatureMax: 1301,
            humidityMin: 8857, humidityMax: 9333,
            pressureMin: 97311, pressureMax: 97395,
            samples: 20,
        ),
        'now' => new MeasurementDataV2(
            temperature: 2157, humidity: 4850, pressure: 97412,
            temperatureMin: 2011, temperatureMax: 2493,
            humidityMin: 4419, humidityMax: 5101,
            pressureMin: 97380, pressureMax: 97466,
            samples: 20,
        ),
    ];
}

function seedHundredthsDay(): void
{
    $sensor = Sensor::factory()->create();

    foreach ([70 => hundredthsDay()['hourAgo'], 10 => hundredthsDay()['now']] as $minutesAgo => $data) {
        Measurement::factory()->for($sensor)->v2()->create([
            'timestamp' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'data' => (string) $data,
        ]);
    }
}

it('prints every temperature in the hero to the hundredth', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    seedHundredthsDay();

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('min 11,05 · max 24,93 °C')
        ->assertSee('between 11,05 and 24,93 °C, now 21,57 °C')
        ->assertSeeInOrder([
            'Measure · CH1', '21,57',
            'rising', '9,23 °C', 'an hour',
            'Min', '11,05 °C',
            'Max', '24,93 °C',
            'Δ / h', '+9,23 °C',
            'Dew pt', '10,24 °C',
        ]);
});

it('shows every other channel with its day extremes below the temperature readout', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    Measurement::factory()->for(Sensor::factory())->v4()->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) new MeasurementDataV4(
            temperature: 1648, humidity: 8784, pressure: 98081,
            temperatureMin: 1600, temperatureMax: 1700,
            humidityMin: 8700, humidityMax: 8800,
            pressureMin: 98070, pressureMax: 98090,
            samples: 20,
            noise: new NoiseWindow(seconds: 600, laeq: 5910, lamax: 6500, la10: 6000, la90: 5500, bands: array_fill(0, 26, 3000)),
            light: new LightWindow(illuminance: 26600, illuminanceMin: 26000, illuminanceMax: 27000),
        ),
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder([
            'Temperature, last 24 h',
            'Measure · CH1', '16,48',
            'bg-ch2', '87,84', '%', 'min', '87,00', 'max', '88,00',
            'bg-ch3', '1 021,39', 'hPa', 'min', '1 021,27', 'max', '1 021,48',
            'bg-ch4', '59,1', 'dB(A)', 'min', '59,1', 'max', '59,1',
            'bg-aux', '266', 'lx', 'min', '260', 'max', '270',
            '>Channels</h2>',
        ], false);
});

it('leaves channels the station does not send out of the strip below the temperature readout', function (): void {
    Measurement::factory()->for(Sensor::factory())->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 1648, humidity: 8784, pressure: 98081),
    ]);

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder(['Measure · CH1', '16,48', '87,84', '1 021,39', '>Channels</h2>'], false)
        ->assertDontSee('dB(A)')
        ->assertDontSee('lx');
});

/**
 * @param  array{int, int, int}  $temperature  mean, min, max in hundredths
 * @param  array{int, int, int}  $humidity  mean, min, max in hundredths
 * @param  array{int, int, int}  $pressure  mean, min, max in pascals
 * @param  array{int, int, int}  $light  mean, min, max in hundredths of lux
 */
function fullWindow(array $temperature, array $humidity, array $pressure, int $laeq, array $light): MeasurementDataV4
{
    return new MeasurementDataV4(
        temperature: $temperature[0], humidity: $humidity[0], pressure: $pressure[0],
        temperatureMin: $temperature[1], temperatureMax: $temperature[2],
        humidityMin: $humidity[1], humidityMax: $humidity[2],
        pressureMin: $pressure[1], pressureMax: $pressure[2],
        samples: 20,
        noise: new NoiseWindow(seconds: 600, laeq: $laeq, lamax: $laeq + 600, la10: $laeq + 100, la90: $laeq - 400, bands: array_fill(0, 26, 3000)),
        light: new LightWindow(illuminance: $light[0], illuminanceMin: $light[1], illuminanceMax: $light[2]),
    );
}

it('updates every readout on the page when a poll brings a new reading', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->v4()->create([
        'timestamp' => now()->subMinutes(65)->getTimestamp(),
        'data' => (string) fullWindow([1648, 1600, 1700], [8784, 8700, 8800], [98081, 98070, 98090], 5910, [26600, 26000, 27000]),
    ]);

    $overview = Livewire::test(Overview::class);
    $overview->assertSeeInOrder(['Measure · CH1', '16,48'])->assertDontSee('18,73');

    Measurement::factory()->for($sensor)->v4()->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) fullWindow([1873, 1811, 1937], [7912, 7855, 7999], [98123, 98101, 98150], 6234, [51234, 48800, 53300]),
    ]);

    $overview->call('$refresh')
        ->assertSee('min 16,00 · max 19,37 °C')
        ->assertSee('between 16,00 and 19,37 °C, now 18,73 °C');

    $this->assertThat([
        'Temperature, last 24 h',
        'Measure · CH1', '18,73',
        'rising', '2,25 °C', 'an hour',
        'Min', '16,00 °C',
        'Max', '19,37 °C',
        'Δ / h', '+2,25 °C',
        'Dew pt', '15,03 °C',
        '79,12', '%', 'min', '78,55', 'max', '88,00',
        '1 021,51', 'hPa', 'min', '1 021,27', 'max', '1 021,79',
        '62,3', 'dB(A)', 'min', '59,1', 'max', '62,3',
        '512', 'lx', 'min', '260', 'max', '533',
        'Channels',
        'Temperature', '18,73', 'min', '16,00', 'max', '19,37', 'Δ/h', '+2,25', 'Dew point 15,03 °C.',
        'Humidity', '79,12', 'min', '78,55', 'max', '88,00', 'Δ/h', '−8,72',
        'Pressure, MSL', '1 021,51', 'min', '1 021,27', 'max', '1 021,79', 'Δ/h', '+0,12',
        'Noise, LAeq', '62,3', 'min', '59,1', 'max', '62,3', 'Δ/h', '+3,2',
        'Light', '512', 'min', '260', 'max', '533', 'Δ/h', '+246',
    ], new SeeInOrder($overview->html()));
});

it('calls the hero temperature steady below five hundredths an hour', function (int $temperatureNow, string $trend): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();

    foreach ([70 => 2000, 10 => $temperatureNow] as $minutesAgo => $temperature) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'data' => (string) new MeasurementDataV1(temperature: $temperature, humidity: 5000, pressure: 97000),
        ]);
    }

    $this->get(route('overview'))->assertOk()->assertSee($trend);
})->with([
    'six hundredths' => [2006, 'rising'],
    'four hundredths' => [2004, 'steady.'],
]);

it('prints temperature, humidity and pressure in the channels to the hundredth', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    seedHundredthsDay();

    $this->get(route('overview'))
        ->assertOk()
        ->assertSeeInOrder([
            'Channels',
            'Temperature', '21,57', '°C', 'min', '11,05', 'max', '24,93', 'Δ/h', '+9,23', 'Dew point 10,24 °C.',
            'Humidity', '48,50', '%', 'min', '44,19', 'max', '93,33', 'Δ/h', '−41,62',
            'Pressure, MSL', '1 013,71', 'hPa', 'min', '1 013,38', 'max', '1 014,84', 'Δ/h', '−1,06',
        ]);
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
        ->assertSeeInOrder(['Measure · CH1', '55,6', 'dB(A)', ', last read, missing from the newest window', '>Channels</h2>'], false)
        ->assertSeeInOrder(['Noise, LAeq', '55,6', 'Missing from the newest window: the figure is the last one read.']);

    expect(Livewire::test(Overview::class)->get('silentChannels'))->toBe([Channel::Noise]);
});

it('reads the noise as now while the newest window carries it', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));
    Measurement::factory()->for(Sensor::factory()->create())->v3()->create([
        'timestamp' => now()->subMinutes(5)->getTimestamp(),
        'data' => (string) noisyWindow(5562, 5762, 5262, 6462, 3000),
    ]);

    $this->get(route('overview'))->assertOk()->assertDontSee('Missing from the newest window')->assertDontSee('missing from the newest window');
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
        ->assertSeeInOrder(['Measure · CH1', 'bg-aux', '1 235', 'lx', 'min', '0', 'max', '6 100', '>Channels</h2>'], false)
        ->assertSeeInOrder(['>Channels</h2>', '>Light</h3>', 'AUX', '1 235', 'lx', 'min', '0', 'max', '6 100', 'VEML7700 in the shield'], false);
});

it('leaves the light out of the hero for a day without it', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v3()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    Livewire::test(Overview::class)
        ->assertDontSee('bg-aux', false)
        ->assertDontSee('>Light</h3>', false)
        ->assertDontSee('VEML7700 in the shield');
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

it('tells how long ago the station last reported', function (int $minutesAgo, string $state): void {
    $this->travelTo(Date::parse('2026-10-04 12:00:00', 'UTC'));
    Measurement::factory()->for(Sensor::factory())->create([
        'timestamp' => now()->subMinutes($minutesAgo)->getTimestamp(),
    ]);

    Livewire::test(Overview::class)
        ->assertSeeInOrder(['The balcony, right now', $state, "{$minutesAgo} minutes ago", '4.10.2026']);
})->with([
    'live' => [7, 'RUN'],
    'silent' => [45, 'STOP'],
]);

it('flashes the status pill only when a newer reading arrives', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(9)->getTimestamp()]);
    $overview = Livewire::test(Overview::class)->assertDontSee('just-arrived', false);

    $overview->call('$refresh')->assertDontSee('just-arrived', false);

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    $overview->call('$refresh')->assertSee('just-arrived', false);

    $overview->call('$refresh')->assertDontSee('just-arrived', false);
});

it('shows the station status once on the overview and in the header elsewhere', function (): void {
    Measurement::factory()->for(Sensor::factory())->create(['timestamp' => now()->subMinutes(5)->getTimestamp()]);

    expect(substr_count((string) $this->get(route('overview'))->getContent(), 'title="Newest reading from the station"'))->toBe(1)
        ->and(substr_count((string) $this->get(route('charts'))->getContent(), 'title="Newest reading from the station"'))->toBe(1);
});

it('does not flash on switching to a sensor with a newer reading', function (): void {
    $north = Sensor::factory()->create(['name' => 'north']);
    $south = Sensor::factory()->create(['name' => 'south']);
    Measurement::factory()->for($north)->create(['timestamp' => now()->subMinutes(9)->getTimestamp()]);
    Measurement::factory()->for($south)->create(['timestamp' => now()->getTimestamp()]);

    Livewire::test(Overview::class)
        ->set('sensor', $south->slug)
        ->assertDontSee('just-arrived', false);
});
