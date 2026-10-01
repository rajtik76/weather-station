<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationReport;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use App\ValueObject\MeasurementDataV3;
use App\ValueObject\NoiseWindow;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('lists a V3 packet with its noise object', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v3()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV3(
            temperature: 2150, humidity: 4800, pressure: 97389,
            temperatureMin: 2100, temperatureMax: 2200,
            humidityMin: 4700, humidityMax: 4900,
            pressureMin: 97380, pressureMax: 97395,
            samples: 20,
            noise: new NoiseWindow(seconds: 600, laeq: 5562, lamax: 5898, la10: 5797, la90: 5284, bands: array_fill(0, 26, 3120)),
        ),
    ]);

    $this->get(route('charts'))
        ->assertOk()
        ->assertSee('"noise"', false)
        ->assertSee('"laeq":5562');
});

it('lists the last three transmissions as the station sent them', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $packets = [
        ['ago' => 40, 'temperature' => 1901, 'humidity' => 4401, 'pressure' => 97001],
        ['ago' => 30, 'temperature' => 2087, 'humidity' => 5941, 'pressure' => 97402],
        ['ago' => 20, 'temperature' => 2112, 'humidity' => 5890, 'pressure' => 97395],
        ['ago' => 10, 'temperature' => 2134, 'humidity' => 5812, 'pressure' => 97389],
    ];

    $sensor = Sensor::factory()->create();

    foreach ($packets as $packet) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => now()->subMinutes($packet['ago'])->getTimestamp(),
            // Buffered on the device, landed five minutes ago.
            'created_at' => now()->subMinutes(5),
            'data' => (string) new MeasurementDataV1(
                temperature: $packet['temperature'],
                humidity: $packet['humidity'],
                pressure: $packet['pressure'],
            ),
        ]);
    }

    $this->get(route('charts'))
        ->assertOk()
        ->assertSee('Last 3 windows')
        ->assertSee('"temperature":2134')
        ->assertSee('5812')
        ->assertSee('97389')
        ->assertSee('2112')
        ->assertSee('2087')
        // 97 389 Pa at 345 m reduces to 1013,5 hPa.
        ->assertSee('1 013,5')
        // Arrival time, Prague: 11:55 UTC is 12:55 in March.
        ->assertSee('15.3.2026 12:55')
        ->assertDontSee('15.3.2026 11:50')
        ->assertDontSee('1901');
});

it('lists a V2 packet under its own keys', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v2()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV2(
            temperature: 2134, humidity: 5812, pressure: 97389,
            temperatureMin: 2101, temperatureMax: 2177,
            humidityMin: 5700, humidityMax: 5900,
            pressureMin: 97380, pressureMax: 97395,
            samples: 20,
        ),
    ]);

    $this->get(route('charts'))
        ->assertOk()
        ->assertSee('"temperature_min":2101')
        ->assertSee('"humidity_max":5900')
        ->assertSee('"pressure_max":97395')
        ->assertSee('"samples":20');
});

it('dates the tail by arrival, not by the measurement', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Measured half an hour ago, delivered two minutes ago.
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(30)->getTimestamp(),
        'created_at' => now()->subMinutes(2),
    ]);

    $html = Livewire::test(Charts::class)->html();

    expect(Str::after($html, 'aria-label="Last transmissions"'))
        ->toContain('15.3.2026 12:58')
        ->not->toContain('15.3.2026 12:30');
});

it('keeps listing the newest transmissions while zoomed into the past', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2134, humidity: 5812, pressure: 97389),
    ]);

    // The tail reads the whole table, not the window.
    Livewire::test(Charts::class)
        ->call('zoomTo', now()->subDays(3)->getTimestamp(), now()->subDays(2)->getTimestamp())
        ->assertSee('Nothing in this range')
        ->assertSee('2134');
});

it('shows what the station last reported about itself', function (): void {
    $this->travelTo(Date::parse('2026-09-17 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    StationReport::factory()->for($sensor)->create([
        'data' => [
            'firmware' => '2.3.0',
            'board' => 'ESP32C3_DEV',
            'reset_reason' => 'task watchdog',
            'uptime' => 3 * 86_400 + 4 * 3_600 + 12 * 60,
            'heap_free' => 187 * 1024,
            'heap_min' => 151 * 1024,
            'ssid' => 'home',
            'ip' => '192.168.0.42',
            'rssi' => -67,
            'wifi_network' => 1,
            'wifi_switches' => 2,
            'buffered' => 3,
            'upload_failures' => 1,
            'clock_step_ms' => 812,
            'clock_step_over_s' => 3600,
            'clock_step_max_ms' => -1204,
            // 11:20 UTC, 13:20 in Prague.
            'clock_synced_at' => now()->subMinutes(40)->getTimestamp(),
        ],
    ]);

    $this->get(route('charts'))
        ->assertOk()
        ->assertSee('aria-label="Station report"', false)
        // 12:00 UTC is 14:00 in Prague in September.
        ->assertSeeInOrder(['Report', '17.9.2026 14:00'])
        ->assertSeeInOrder(['firmware', '2.3.0'])
        ->assertSeeInOrder(['board', 'ESP32C3_DEV'])
        ->assertSeeInOrder(['uptime', '3 d 4 h'])
        ->assertSeeInOrder(['last reset', 'task watchdog'])
        ->assertSeeInOrder(['network', 'backup'])
        // The view prints a true minus sign (U+2212) before the figure.
        ->assertSeeInOrder(['RSSI', "\u{2212}67 dBm"])
        // Public page: the network's role, never SSID or address.
        ->assertDontSee('home')
        ->assertDontSee('192.168.0.42')
        ->assertSeeInOrder(['heap free', '187 kB'])
        ->assertSeeInOrder(['heap lowest', '151 kB'])
        ->assertSeeInOrder(['buffered', '3 windows'])
        ->assertSeeInOrder(['failed uploads', '1 in a row'])
        ->assertSeeInOrder(['network switches', '2'])
        ->assertSeeInOrder(['clock drift', '+812 ms in 1 h 0 min'])
        ->assertSeeInOrder(['clock drift worst', '−1 204 ms'])
        ->assertSeeInOrder(['clock synced', '17.9.2026 13:20']);
});

it('shows no clock drift before the station has re-synced once', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);

    // Zeros after a fresh boot (the boot sync is not a drift); nothing at all before firmware 2.2.
    foreach ([
        ['clock_step_ms' => 0, 'clock_step_over_s' => 0, 'clock_step_max_ms' => 0, 'clock_synced_at' => now()->getTimestamp()],
        [],
    ] as $clock) {
        StationReport::query()->delete();
        $data = StationReport::factory()->raw()['data'];
        unset($data['clock_step_ms'], $data['clock_step_over_s'], $data['clock_step_max_ms'], $data['clock_synced_at']);
        StationReport::factory()->for($sensor)->create(['data' => [...$data, ...$clock]]);

        $this->get(route('charts'))
            ->assertOk()
            ->assertSee('aria-label="Station report"', false)
            ->assertDontSee('clock drift')
            ->assertDontSee('clock synced');
    }
});

it('shows no board before firmware 2.3 reported one', function (): void {
    $sensor = Sensor::factory()->create();
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->getTimestamp()]);
    $data = StationReport::factory()->raw()['data'];
    unset($data['board']);
    StationReport::factory()->for($sensor)->create(['data' => $data]);

    $this->get(route('charts'))
        ->assertOk()
        ->assertSee('aria-label="Station report"', false)
        ->assertDontSee('>board</dt>', false);
});

it('shows no station report before the firmware has reported', function (): void {
    Measurement::factory()->create(['timestamp' => now()->getTimestamp()]);

    $this->get(route('charts'))
        ->assertOk()
        ->assertDontSee('aria-label="Station report"', false);
});

it('shows the selected sensor\'s report, not another station\'s', function (): void {
    $shown = Sensor::factory()->create(['name' => 'north']);
    $other = Sensor::factory()->create(['name' => 'south']);
    StationReport::factory()->for($shown)->create(['data' => [...StationReport::factory()->raw()['data'], 'firmware' => 'north-build']]);
    StationReport::factory()->for($other)->create(['data' => [...StationReport::factory()->raw()['data'], 'firmware' => 'south-build']]);

    $this->get(route('charts', ['sensor' => 'north']))
        ->assertOk()
        ->assertSee('north-build')
        ->assertDontSee('south-build');
});
