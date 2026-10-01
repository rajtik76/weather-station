<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('renders the readings stored in the database', function (): void {
    $at = now()->subHour();

    Measurement::factory()->create([
        'timestamp' => $at->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $this->get(route('charts'))
        ->assertOk()
        // 2150 -> 21,50 °C, 4800 -> 48,00 %; 97 389 Pa at 345 m and 21,50 °C reduces to 1013,5 hPa.
        ->assertSee('21,50')
        ->assertSee('48,00')
        ->assertSee('1 013,5')
        ->assertDontSee('Waiting for the first reading');
});

it('labels readings in Czech local time, not UTC', function (): void {
    // 12:00 UTC in July is 14:00 in Prague (CEST, UTC+2).
    Measurement::factory()->create([
        'timestamp' => Date::parse('2026-07-15 12:00:00', 'UTC')->getTimestamp(),
    ]);

    $this->travelTo(Date::parse('2026-07-15 13:00:00', 'UTC'));

    $this->get(route('charts'))
        ->assertOk()
        // 12:00 UTC + 2 h, as milliseconds (see LocalTime::wallClockMs).
        ->assertSee('1784124000000')
        ->assertSee('15.7.2026 14:00');
});

it('labels readings in standard time outside the summer window', function (): void {
    // 12:00 UTC in January is 13:00 in Prague (CET, UTC+1).
    Measurement::factory()->create([
        'timestamp' => Date::parse('2026-01-15 12:00:00', 'UTC')->getTimestamp(),
    ]);

    $this->travelTo(Date::parse('2026-01-15 13:00:00', 'UTC'));

    $this->get(route('charts'))
        ->assertOk()
        // 12:00 UTC + 1 h, as milliseconds.
        ->assertSee('1768482000000')
        ->assertSee('15.1.2026 13:00');
});

it('shows an empty state when nothing has been recorded', function (): void {
    $this->get(route('charts'))
        ->assertOk()
        ->assertSee('Nothing in this range');
});
it('averages a long range into buckets', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    $sensor = Sensor::factory()->create();

    // A day of ten-minute slots, +0,1 °C per slot so every bucket's mean differs.
    foreach (range(0, 143) as $slot) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => $start->getTimestamp() + $slot * 600,
            'data' => (string) new MeasurementDataV1(temperature: 1000 + $slot * 10, humidity: 5000, pressure: 97389),
        ]);
    }

    $month = filledBuckets(Livewire::test(Charts::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html());

    // A month is hourly buckets, stamped on the slot, not on a reading.
    expect(array_keys($month))->toBe(array_map(
        fn (int $hour): int => $start->getTimestamp() + $hour * 3600,
        range(0, 23),
    ));

    // First bucket: slots 0-5, 10,00 to 10,50 °C, mean 10,25. Whole numbers decode as integers.
    $first = $month[$start->getTimestamp()];

    expect($first[1])->toBe(10.25)
        ->and($first[2])->toEqual(50)
        // 1772326800000 is midnight UTC on the 1st, in Prague's wall clock.
        ->and($first[0])->toBe(1772326800000);
});

it('stamps a bucket on its slot whatever time its readings carry', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    // The same day stamped fifteen minutes off every slot, as a real station drifts.
    $sensor = Sensor::factory()->create();

    foreach (range(0, 143) as $slot) {
        Measurement::factory()->for($sensor)->create(['timestamp' => $start->getTimestamp() + $slot * 600 + 900]);
    }

    $month = filledBuckets(Livewire::test(Charts::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html());

    // 23:55 spills into the midnight bucket, so 25.
    expect(array_keys($month))->toBe(array_map(
        fn (int $hour): int => $start->getTimestamp() + $hour * 3600,
        range(0, 24),
    ));
});

it('draws a missed slot as a hole rather than joining its neighbours', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    // 11:30 and 11:50 arrived; the 11:40 upload never did.
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(30)->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $rows = bucketRows(Livewire::test(Charts::class)
        ->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp())
        ->html());

    // Thirteen slots 10:00-12:00, the missed ones as nulls.
    $byEpoch = array_combine(array_column($rows, 5), $rows);

    expect($rows)->toHaveCount(13)
        ->and($byEpoch[now()->subMinutes(30)->getTimestamp()][1])->not->toBeNull()
        ->and($byEpoch[now()->subMinutes(10)->getTimestamp()][1])->not->toBeNull()
        ->and($byEpoch[now()->subMinutes(20)->getTimestamp()])
        ->toBe([1773578400000, null, null, null, null, 1773574800, null, null, null, null, null, null])
        ->and($byEpoch[now()->getTimestamp()][1])->toBeNull();
});

it('counts stored readings in the footer, not slots', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(30)->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

    // Thirteen slots, two filled; the third reading is outside the window.
    Livewire::test(Charts::class)
        ->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp())
        ->assertSee('2 records')
        ->assertDontSee('13 records');
});
it('plots pressure at the sensor\'s own resolution', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2134, humidity: 5000, pressure: 97400),
    ]);

    $html = Livewire::test(Charts::class)->html();

    // Whole pascals are hundredths of a hectopascal; tenths drew a staircase.
    expect(chartRows($html))->toContain('1013.62');

    // Readouts and tail print tenths.
    expect($html)->toContain('1 013,6');
});

it('carries the dew point in the chart payload', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $html = Livewire::test(Charts::class)->html();

    // 21,50 °C at 48 % condenses at about 10 °C.
    $row = array_values(filledBuckets($html))[0];

    expect($row)->toHaveCount(12)
        ->and($row[4])->toBe(10.02)
        ->and(navigatorRows($html)[0][4])->toBe(10.02);
});

it('carries the spread of the samples behind each bucket', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    $sensor = Sensor::factory()->create();

    // The hour's band is the extreme sample of either window, not the extreme mean.
    foreach ([[2100, 1950, 2380, 5000, 4800, 5300, 97389, 97380, 97395], [2200, 2100, 2250, 4900, 4750, 5100, 97400, 97390, 97410]] as $slot => [$t, $tMin, $tMax, $h, $hMin, $hMax, $p, $pMin, $pMax]) {
        Measurement::factory()->for($sensor)->v2()->create([
            'timestamp' => $start->getTimestamp() + $slot * 600,
            'data' => (string) new MeasurementDataV2(
                temperature: $t, humidity: $h, pressure: $p,
                temperatureMin: $tMin, temperatureMax: $tMax,
                humidityMin: $hMin, humidityMax: $hMax,
                pressureMin: $pMin, pressureMax: $pMax,
                samples: 20,
            ),
        ]);
    }

    $hour = array_values(filledBuckets(Livewire::test(Charts::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html()))[0];

    expect(array_slice($hour, 6, 4))->toEqual([19.5, 23.8, 47.5, 53.0])
        // Reduced with the mean's temperature: 97 380 Pa at 21,5 °C and 345 m is 1013,39 hPa.
        ->and($hour[10])->toBe(1013.39)
        ->and($hour[11])->toBe(1013.7)
        ->and($hour[3])->toBeGreaterThan(1013.39)
        ->and($hour[3])->toBeLessThan(1013.7);
});
it('bands a V1 reading on itself', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // A V1 entry is its own extreme: the band is there, with no width.
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $row = array_values(filledBuckets(Livewire::test(Charts::class)->html()))[0];

    expect(array_slice($row, 6))->toEqual([21.5, 21.5, 48.0, 48.0, $row[3], $row[3]]);
});
