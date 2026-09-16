<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Livewire\Dashboard;
use App\Models\Measurement;
use App\Models\Sensor;
use App\Models\StationEvent;
use App\ValueObject\MeasurementDataV1;
use App\ValueObject\MeasurementDataV2;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The window's own chart payload.
 *
 * The page also carries the navigator's payload, which spans the whole record
 * by design - asserting against the raw HTML would find readings the window
 * deliberately excludes.
 */
function chartRows(string $html): string
{
    preg_match('/data-chart-rows="([^"]*)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? '');
}

/**
 * The window's payload decoded, one row per bucket - holes included.
 *
 * @return list<array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}>
 */
function bucketRows(string $html): array
{
    return json_decode(chartRows($html) ?: '[]', true);
}

/**
 * Only the buckets a reading landed in, keyed by their epoch.
 *
 * @return array<int, array{0: int, 1: ?float, 2: ?float, 3: ?float, 4: ?float, 5: int, 6: ?float, 7: ?float, 8: ?float, 9: ?float, 10: ?float, 11: ?float}>
 */
function filledBuckets(string $html): array
{
    $filled = [];

    foreach (bucketRows($html) as $row) {
        if ($row[1] !== null) {
            $filled[$row[5]] = $row;
        }
    }

    return $filled;
}

/**
 * The navigator's own payload, which always spans the whole record.
 *
 * @return list<array{0: int, 1: float, 2: float, 3: float, 4: ?float, 5: int}>
 */
function navigatorRows(string $html): array
{
    preg_match('/data-navigator-rows="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

/**
 * The events the charts are told to mark.
 *
 * @return list<array{0: int, 1: string, 2: ?string}>
 */
function chartEvents(string $html): array
{
    preg_match('/data-chart-events="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

it('renders the readings stored in the database', function (): void {
    $at = now()->subHour();

    Measurement::factory()->create([
        'timestamp' => $at->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $this->get('/')
        ->assertOk()
        // Raw units are converted for display, keeping the sensor's two decimals:
        // 2150 -> 21,50 °C, 4800 -> 48,00 %. Pressure is also reduced to sea
        // level, so 97 389 Pa read at 345 m and 21,50 °C shows as 1013,5 hPa.
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

    $this->get('/')
        ->assertOk()
        // 12:00 UTC + 2 h, as milliseconds: the chart reads its axis as UTC,
        // so the offset is folded into the value before it leaves the server.
        ->assertSee('1784124000000')
        ->assertSee('15. 7. 2026 14:00');
});

it('labels readings in standard time outside the summer window', function (): void {
    // 12:00 UTC in January is 13:00 in Prague (CET, UTC+1).
    Measurement::factory()->create([
        'timestamp' => Date::parse('2026-01-15 12:00:00', 'UTC')->getTimestamp(),
    ]);

    $this->travelTo(Date::parse('2026-01-15 13:00:00', 'UTC'));

    $this->get('/')
        ->assertOk()
        // 12:00 UTC + 1 h, as milliseconds.
        ->assertSee('1768482000000')
        ->assertSee('15. 1. 2026 13:00');
});

it('shows an empty state when nothing has been recorded', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Nothing in this range');
});

it('ignores readings older than the window', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subDays(40)->getTimestamp(),
    ]);

    $this->get('/')->assertSee('Nothing in this range');
});

it('opens on the last week', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::test(Dashboard::class)
        ->assertSet('from', null)
        ->assertSet('to', null)
        ->assertSee('8. 3. 2026 → 15. 3. 2026');
});

it('takes the window from the query string', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::withQueryParams([
        'from' => Date::parse('2026-03-10 00:00:00', 'UTC')->getTimestamp(),
        'to' => Date::parse('2026-03-12 00:00:00', 'UTC')->getTimestamp(),
    ]);

    Livewire::test(Dashboard::class)->assertSee('10. 3. 2026 → 12. 3. 2026');
});

it('plots only the readings inside the window', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subHour()->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subDays(10)->getTimestamp()]);

    // Prague runs an hour ahead of UTC in March, and the payload carries that
    // shift already (see wallClockMs() on the component).
    $recent = '1773576000000';
    $older = '1772715600000';

    $wide = chartRows(Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subDays(20)->getTimestamp(), now()->getTimestamp())
        ->html());

    $narrow = chartRows(Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp())
        ->html());

    expect($narrow)->toContain($recent)->not->toContain($older)
        ->and($wide)->toContain($recent)->toContain($older);
});

it('averages a long range into buckets', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    $sensor = Sensor::factory()->create();

    // A full day at the station's ten-minute cadence, warming a tenth of a
    // degree per slot so every bucket has a mean of its own.
    foreach (range(0, 143) as $slot) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => $start->getTimestamp() + $slot * 600,
            'data' => (string) new MeasurementDataV1(temperature: 1000 + $slot * 10, humidity: 5000, pressure: 97389),
        ]);
    }

    $month = filledBuckets(Livewire::test(Dashboard::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html());

    // A month is drawn in hourly buckets, twenty-four to the day, each
    // stamped on the slot it covers rather than on any reading inside it.
    expect(array_keys($month))->toBe(array_map(
        fn (int $hour): int => $start->getTimestamp() + $hour * 3600,
        range(0, 23),
    ));

    // The first bucket holds slots 0-5: 10,00 °C up to 10,50 °C, so its mean
    // is 10,25 °C. Whole numbers come back from the JSON as integers.
    $first = $month[$start->getTimestamp()];

    expect($first[1])->toBe(10.25)
        ->and($first[2])->toEqual(50)
        // 1772326800000 is midnight UTC on the 1st, in Prague's wall clock.
        ->and($first[0])->toBe(1772326800000);
});

it('stamps a bucket on its slot whatever time its readings carry', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    // The same day, stamped fifteen minutes off every slot - the drift a
    // station accumulates by uploading when it wakes. The buckets divide the
    // epoch, so a drifting reading still lands in the slot it belongs to and
    // the row is dated by that slot, not by the reading.
    $sensor = Sensor::factory()->create();

    foreach (range(0, 143) as $slot) {
        Measurement::factory()->for($sensor)->create(['timestamp' => $start->getTimestamp() + $slot * 600 + 900]);
    }

    $month = filledBuckets(Livewire::test(Dashboard::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html());

    // Hourly buckets: the last reading, at 23:55, spills into the one that
    // opens at midnight - the window's own last slot - so there are 25.
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

    $rows = bucketRows(Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp())
        ->html());

    // Every ten-minute slot from 10:00 to 12:00 is a row, thirteen in all,
    // and the ones the station missed carry nothing but their stamp.
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

    // The payload is thirteen slots for a two-hour window; the station sent
    // two of them, and the third reading lies outside the window.
    Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp())
        ->assertSee('2 records')
        ->assertDontSee('13 records');
});

it('widens the buckets with the window', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    // Two readings twenty minutes apart, inside one half hour.
    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->subMinutes(20)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2000, humidity: 5000, pressure: 97389),
    ]);
    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2200, humidity: 5000, pressure: 97389),
    ]);

    // A week is drawn in half-hour buckets: the pair averages into one point
    // on the half hour.
    $week = filledBuckets(Livewire::test(Dashboard::class)->html());

    expect($week)->toHaveCount(1)
        ->and(array_key_first($week))->toBe(now()->subMinutes(30)->getTimestamp())
        ->and(array_values($week)[0][1])->toEqual(21);

    // Zoomed to an hour the buckets are the station's own slots, and each
    // reading is a point again.
    $hour = filledBuckets(Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subHour()->getTimestamp(), now()->getTimestamp())
        ->html());

    expect(array_column($hour, 1))->toEqual([20, 22]);
});

it('picks the bucket width from the span on screen', function (int $days, int $bucketSeconds, array $epochs): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    // One hour of uploads, 11:00 to 11:50, warming a degree per slot.
    foreach (range(0, 5) as $slot) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => now()->subHour()->getTimestamp() + $slot * 600,
            'data' => (string) new MeasurementDataV1(temperature: 1000 + $slot * 100, humidity: 5000, pressure: 97389),
        ]);
    }

    $html = Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subDays($days)->getTimestamp(), now()->getTimestamp())
        ->html();

    $rows = bucketRows($html);
    $filled = filledBuckets($html);

    // Every slot of the window is a row, the hour falls into as many of
    // them as the width allows, and each is stamped on its slot.
    expect($rows)->toHaveCount(intdiv($days * 86400, $bucketSeconds) + 1)
        ->and(array_column($rows, 5))->each->toBeIn(range($rows[0][5], now()->getTimestamp(), $bucketSeconds))
        ->and(array_keys($filled))->toBe(array_map(fn (int $epoch): int => now()->getTimestamp() + $epoch, $epochs));
})->with([
    // 11:00 and 11:30 on a week, every half hour.
    '7 days' => [7, 1800, [-3600, -1800]],
    // A fortnight is drawn as a month: whole hours.
    '14 days' => [14, 3600, [-3600]],
    '1 month' => [30, 3600, [-3600]],
]);

it('draws no wider than a month', function (int $days): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    // A strip is a thousand pixels across; a year on it is three per day.
    // The window is clipped from the front to the month ending where the
    // reader pointed, and the buckets stay hourly.
    $component = Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subDays($days)->getTimestamp(), now()->getTimestamp())
        ->assertSet('to', now()->getTimestamp())
        ->assertSet('from', now()->getTimestamp() - 30 * 86400)
        ->assertSee('13. 2. 2026 → 15. 3. 2026');

    expect(bucketRows($component->html()))->toHaveCount(30 * 24 + 1);
})->with([
    '2 months' => [61],
    '1 year' => [365],
]);

it('clips a window wider than a month from the query string', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::withQueryParams([
        'from' => Date::parse('2025-03-15 12:00:00', 'UTC')->getTimestamp(),
        'to' => Date::parse('2026-03-10 12:00:00', 'UTC')->getTimestamp(),
    ]);

    Livewire::test(Dashboard::class)
        ->assertSet('to', Date::parse('2026-03-10 12:00:00', 'UTC')->getTimestamp())
        ->assertSet('from', Date::parse('2026-02-08 12:00:00', 'UTC')->getTimestamp());
});

it('reads the hero off the newest bucket that holds a reading', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Quiet for three days: the window ends in a run of empty slots, and the
    // readouts must find the reading behind them rather than a hole.
    Measurement::factory()->create([
        'timestamp' => now()->subDays(3)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('21,50')
        ->assertSee('48,00')
        ->assertSee('1 013,5');
});

it('plots pressure at the sensor\'s own resolution', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2134, humidity: 5000, pressure: 97400),
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    // The strip's axis scales to whatever the window holds, and a day of
    // weather is a couple of hPa, so tenths drew the line as a staircase. The
    // station reports whole pascals, which is a hundredth of a hectopascal.
    expect(chartRows($html))->toContain('1013.62');

    // The readouts and the payload tail still print a tenth of it.
    expect($html)->toContain('1 013,6');
});

it('carries the dew point in the chart payload', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    // 21,50 °C at 48 % condenses at about 10 °C. Derived on the server so the
    // chart draws it like any other column, between the pressure and the epoch.
    $row = array_values(filledBuckets($html))[0];

    expect($row)->toHaveCount(12)
        ->and($row[4])->toBe(10.02)
        ->and(navigatorRows($html)[0][4])->toBe(10.02);
});

it('carries the spread of the samples behind each bucket', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    $sensor = Sensor::factory()->create();

    // Two V2 windows in the same hour, each a mean with the extremes of the
    // samples behind it. The hour's band is the coldest and warmest sample
    // of either, not the extremes of the means.
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

    $hour = array_values(filledBuckets(Livewire::test(Dashboard::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html()))[0];

    expect(array_slice($hour, 6, 4))->toEqual([19.5, 23.8, 47.5, 53.0])
        // The pressure extremes are reduced to sea level like the mean, with
        // the mean's temperature: 97 380 Pa at 21,5 °C and 345 m is 1013,39 hPa.
        ->and($hour[10])->toBe(1013.39)
        ->and($hour[11])->toBe(1013.7)
        ->and($hour[3])->toBeGreaterThan(1013.39)
        ->and($hour[3])->toBeLessThan(1013.7);
});

it('bands a V1 reading on itself', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // A V1 entry is one sample, so at the station's own cadence the band
    // has no width: the row still carries it, equal to the mean, so the
    // chart draws every bucket the same way.
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $row = array_values(filledBuckets(Livewire::test(Dashboard::class)->html()))[0];

    expect(array_slice($row, 6))->toEqual([21.5, 21.5, 48.0, 48.0, $row[3], $row[3]]);
});

it('reads the day\'s extremes off the samples rather than the means', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    // The night's coldest window averaged 2,10 °C, but one sample in it read
    // 1,05 °C; the noon window averaged 21,50 °C with a sample at 24,90 °C.
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

    $this->get('/')
        ->assertOk()
        ->assertSee('21,50')
        ->assertSee('min 1,05 · max 24,90')
        ->assertSee('min 44,00 · max 93,00');
});

it('keeps the dew point off until the reader asks for it', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $component = Livewire::test(Dashboard::class);

    // Derived rather than measured, so it is a third line the reader opts
    // into. Its label on the shared strip is the switch; the payload tells
    // the chart which lines to leave out.
    expect($component->html())
        ->toContain('data-hidden-channels="[&quot;d&quot;]"')
        ->toContain('aria-pressed="false"');

    expect($component->call('toggleChannel', 'd')->html())
        ->toContain('data-hidden-channels="[]"')
        ->not->toContain('aria-pressed="false"');

    expect($component->call('toggleChannel', 'd')->html())
        ->toContain('data-hidden-channels="[&quot;d&quot;]"');
});

it('never lets the shared strip go blank', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $component = Livewire::test(Dashboard::class)
        ->call('toggleChannel', 't');

    // Temperature off leaves humidity on its own, so its switch is disabled
    // and pressing it anyway changes nothing.
    expect($component->html())
        ->toContain('data-hidden-channels="[&quot;t&quot;,&quot;d&quot;]"')
        ->toMatch('/toggleChannel\(\'h\'\)"[^>]*disabled/')
        ->not->toMatch('/toggleChannel\(\'t\'\)"[^>]*disabled/');

    expect($component->call('toggleChannel', 'h')->html())
        ->toContain('data-hidden-channels="[&quot;t&quot;,&quot;d&quot;]"');

    // Bringing another line back frees it again.
    expect($component->call('toggleChannel', 'd')->call('toggleChannel', 'h')->html())
        ->toContain('data-hidden-channels="[&quot;t&quot;,&quot;h&quot;]"');
});

it('ignores a switch for a channel the strip does not have', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    expect(Livewire::test(Dashboard::class)->call('toggleChannel', 'p')->html())
        ->toContain('data-hidden-channels="[&quot;d&quot;]"');
});

it('narrows the window to a dragged selection', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subHour()->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

    $recent = '1773576000000';
    $older = '1773320400000';

    $component = Livewire::test(Dashboard::class);

    expect(chartRows($component->html()))->toContain($recent)->toContain($older);

    // The chart hands back real epochs, not the shifted stamps it plots.
    $component->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp());

    expect(chartRows($component->html()))->toContain($recent)->not->toContain($older);

    $component->call('resetZoom');

    expect(chartRows($component->html()))->toContain($older);
});

it('orders and widens a backwards or tiny selection', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Dragged right to left, and far too narrow to draw a line through.
    Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->getTimestamp(), now()->subMinutes(5)->getTimestamp())
        ->assertSet('to', now()->getTimestamp())
        ->assertSet('from', now()->getTimestamp() - 2400);
});

it('refuses a window from the future', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::withQueryParams([
        'from' => now()->addDay()->getTimestamp(),
        'to' => now()->addDays(2)->getTimestamp(),
    ]);

    Livewire::test(Dashboard::class)->assertSet('to', now()->getTimestamp());
});

it('names the window it is showing', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subDay()->getTimestamp(), now()->getTimestamp())
        // 12:00 UTC is 13:00 in Prague, and a day-wide window names the clock.
        ->assertSee('14. 3. 2026 13:00 → 15. 3. 2026 13:00');
});

it('reports when the station last transmitted', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subMinutes(12)->getTimestamp()]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Last transmission')
        // 11:48 UTC is 12:48 in Prague, which is still on CET in mid-March.
        ->assertSee('15. 3. 2026 12:48')
        ->assertSee('12 minutes ago');
});

it('does not report a reading as arriving in the future', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // The station syncs NTP once and then free-runs, so its clock can sit
    // ahead of the server's.
    Measurement::factory()->create(['timestamp' => now()->addMinutes(4)->getTimestamp()]);

    $this->get('/')
        ->assertOk()
        ->assertSee('just now')
        ->assertDontSee('from now');
});

it('still reports the last measurement when the window holds nothing', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

    Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subHour()->getTimestamp(), now()->getTimestamp())
        ->assertSee('Last measurement')
        ->assertSee('Nothing in this range');
});

it('calls the station silent after three missed slots', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subMinutes(20)->getTimestamp()]);
    Livewire::test(Dashboard::class)
        ->assertSee('Station live')
        // The indicator breathes while the link holds.
        ->assertSee('animate-breathe', escape: false);

    Measurement::query()->delete();

    Measurement::factory()->create(['timestamp' => now()->subMinutes(40)->getTimestamp()]);
    Livewire::test(Dashboard::class)
        ->assertSee('Station silent')
        ->assertDontSee('animate-breathe', escape: false);
});

it('credits the author in the footer', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Vladislav Rajtmajer')
        ->assertSee((string) now()->year)
        ->assertSee('https://github.com/rajtik76');
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
            // The batch was buffered on the device and landed five minutes ago,
            // whatever each reading's own stamp says.
            'created_at' => now()->subMinutes(5),
            'data' => (string) new MeasurementDataV1(
                temperature: $packet['temperature'],
                humidity: $packet['humidity'],
                pressure: $packet['pressure'],
            ),
        ]);
    }

    $this->get('/')
        ->assertOk()
        // The protocol's own fixed point integers, as the endpoint received them.
        ->assertSee('"temperature": <span class="text-amber-600">2134</span>', false)
        ->assertSee('5812')
        ->assertSee('97389')
        ->assertSee('2112')
        ->assertSee('2087')
        // Converted alongside: 97 389 Pa read at 345 m reduces to 1013,5 hPa at
        // sea level.
        ->assertSee('1 013,5')
        // The date is the arrival, in Prague time - 11:55 UTC is 12:55 there in
        // March. The reading's own stamp is in the JSON beside it and is not
        // repeated as a date.
        ->assertSee('15. 3. 2026 12:55')
        ->assertDontSee('15. 3. 2026 11:50')
        // A fourth packet, and the oldest of them, has scrolled off the tail.
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

    // The tail prints the entry as it arrived, so a window's extremes and its
    // sample count are listed beside the mean, each in its channel's colour.
    $this->get('/')
        ->assertOk()
        ->assertSee('"temperature_min": <span class="text-amber-600">2101</span>', false)
        ->assertSee('"humidity_max": <span class="text-cyan-600">5900</span>', false)
        ->assertSee('"pressure_max": <span class="text-violet-600 dark:text-violet-500">97395</span>', false)
        ->assertSee('"samples": <span class="text-zinc-700 dark:text-zinc-300">20</span>', false);
});

it('dates the tail by arrival while the readout dates the measurement', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Measured half an hour ago, delivered two minutes ago - the shape of a
    // batch the station buffered while its link was down.
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(30)->getTimestamp(),
        'created_at' => now()->subMinutes(2),
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    // The readout answers how long the station has been quiet, so it reads the
    // measurement: 11:30 UTC is 12:30 in Prague.
    expect(Str::before($html, 'data-chart-rows'))->toContain('15. 3. 2026 12:30')
        // The tail answers when the row reached the server: 11:58 UTC, 12:58 there.
        ->and(Str::after($html, 'aria-label="Last transmissions"'))
        ->toContain('15. 3. 2026 12:58')
        ->not->toContain('15. 3. 2026 12:30');
});

it('draws temperature and humidity on one strip and pressure on another', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Dashboard::class)->html();

    // Two canvases, three headers: the shared strip reports each channel
    // against its own unit above the one grid they are drawn on.
    expect(substr_count($html, 'data-canvas'))->toBe(2)
        ->and(substr_count($html, 'data-strip="th"'))->toBe(1)
        ->and(substr_count($html, 'data-strip="p"'))->toBe(1)
        ->and($html)->toContain('Temperature (°C)')
        ->toContain('Humidity (%)')
        ->toContain('Pressure, MSL (hPa)')
        // The payload tail reads as a footnote to the charts, so it follows them.
        ->and(Str::after($html, 'data-canvas'))->toContain('when they arrived')
        ->and(Str::before($html, 'data-canvas'))->not->toContain('when they arrived');
});

it('puts the navigator above the strips it scrolls', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Dashboard::class)->html();

    // It sets the window rather than reporting one, so it belongs with the
    // range switcher: below the strips a drag would move a grid that had
    // scrolled off the screen. Matched on the section label, because
    // `data-navigator-rows` in the payload would otherwise be the first hit.
    expect(Str::before($html, 'aria-label="Whole record"'))->toContain('Range')
        ->not->toContain('data-strip=')
        ->and(Str::after($html, 'aria-label="Whole record"'))->toContain('data-strip="th"');
});

it('labels the shared strip with each channel and its unit', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    // The hero above also prints the current reading, so the assertion is
    // pinned to the band between the chart payload and the strip's canvas.
    $headers = Str::before(Str::after($html, 'data-chart-rows'), 'data-strip="th"');

    expect($headers)->toContain('Temperature (°C)')
        ->toContain('Dew point (°C)')
        ->toContain('Humidity (%)')
        // Labels only: the figures live on the canvas and in the hero.
        ->not->toContain('21,50')
        ->not->toContain('48,00')
        // Pressure is headed above its own strip, not this one.
        ->not->toContain('Pressure, MSL');
});

it('draws the navigator for a record shorter than one thinning bucket', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Three hours of uploads, stamped when the station woke rather than on the
    // slot - the drift is what a real ESP32 sends. A whole record this short
    // holds no row at all near a six-hour boundary, and thinning by the phase
    // of the epoch left the navigator with nothing to draw.
    $sensor = Sensor::factory()->create();

    foreach (range(1, 18) as $slot) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => now()->subMinutes($slot * 10)->getTimestamp() + 122,
        ]);
    }

    expect(navigatorRows(Livewire::test(Dashboard::class)->html()))->toHaveCount(18);
});

it('thins the navigator to one point per bucket once the record is long', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $data = (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389);
    $sensor = Sensor::factory()->create();

    // Eleven days of ten-minute uploads, drifting off the slot as above. That
    // is 44 six-hour buckets, and the navigator keeps the first row of each.
    $rows = collect(range(1, 1584))->map(fn (int $slot): array => [
        'sensor_id' => $sensor->id,
        'timestamp' => now()->subMinutes($slot * 10)->getTimestamp() + 122,
        'protocol_version' => ProtocolVersion::V1->value,
        'data' => $data,
    ]);

    Measurement::insert($rows->all());

    expect(navigatorRows(Livewire::test(Dashboard::class)->html()))
        ->toHaveCount(44);
});

it('keeps listing the newest transmissions while zoomed into the past', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2134, humidity: 5812, pressure: 97389),
    ]);

    // The tail reads across the whole table, so a window holding nothing still
    // reports what the station last sent.
    Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subDays(3)->getTimestamp(), now()->subDays(2)->getTimestamp())
        ->assertSee('Nothing in this range')
        ->assertSee('2134');
});

it('polls for readings that arrive while the page is open', function (): void {
    Livewire::test(Dashboard::class)->assertSee('wire:poll.60s', escape: false);
});

it('serves the station mark as the favicon', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('href="/favicon.svg"', escape: false)
        ->assertSee('href="/favicon.ico"', escape: false);

    expect(public_path('favicon.svg'))->toBeReadableFile()
        ->and(public_path('favicon.ico'))->toBeReadableFile()
        ->and(public_path('apple-touch-icon.png'))->toBeReadableFile();
});

it('hands the map the station area to draw', function (): void {
    Livewire::test(Dashboard::class)
        ->assertSee('data-lat="49.733242"', escape: false)
        ->assertSee('data-lng="13.399911"', escape: false)
        ->assertSee('data-radius="800"', escape: false);
});

it('marks station events on the charts in Czech local time', function (): void {
    // 10:00 UTC in July is 12:00 in Prague (CEST, UTC+2).
    StationEvent::factory()->create([
        'occurred_at' => Date::parse('2026-07-15 10:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
        'color' => '#71717a',
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    // The stamp is shifted the same way as the readings, so the mark lands
    // on the axis where the readings of that instant do.
    expect(chartEvents($html))->toBe([
        [1784116800000, 'Radiation shield fitted', '#71717a'],
    ]);
});

it('leaves the colour to the chart when the event was entered without one', function (): void {
    StationEvent::factory()->create([
        'occurred_at' => Date::parse('2026-07-15 10:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
        'color' => null,
    ]);

    expect(chartEvents(Livewire::test(Dashboard::class)->html()))->toBe([
        [1784116800000, 'Radiation shield fitted', null],
    ]);
});

it('marks events in the order they happened whatever order they were entered', function (): void {
    $sensor = Sensor::factory()->create();

    StationEvent::factory()->for($sensor)->create([
        'occurred_at' => Date::parse('2026-08-01 08:00:00', 'UTC'),
        'title' => 'Moved to the south wall',
    ]);
    StationEvent::factory()->for($sensor)->create([
        'occurred_at' => Date::parse('2026-06-01 08:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    expect(array_column(chartEvents($html), 1))
        ->toBe(['Radiation shield fitted', 'Moved to the south wall']);
});

it('hands the charts no events when none have been recorded', function (): void {
    expect(chartEvents(Livewire::test(Dashboard::class)->html()))->toBe([]);
});

it('names the sensor it is showing', function (): void {
    Sensor::factory()->create([
        'name' => 'bme280-north',
        'description' => 'Under the eaves on the north wall, in a radiation shield.',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSeeInOrder(['Sensor', 'bme280-north', 'Under the eaves on the north wall, in a radiation shield.'])
        ->assertDontSee('none registered yet');
});

it('reports when no sensor has registered yet', function (): void {
    Livewire::test(Dashboard::class)
        ->assertSee('none registered yet')
        ->assertSet('sensor', null);
});

it('offers a picker only once there are two sensors', function (): void {
    Sensor::factory()->create();

    Livewire::test(Dashboard::class)->assertDontSee('Choose a sensor');

    Sensor::factory()->create();

    Livewire::test(Dashboard::class)->assertSee('Choose a sensor');
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

    $component = Livewire::test(Dashboard::class)
        ->assertSet('sensor', 'first')
        // Readouts, chart, status line and payload tail all read the first sensor.
        ->assertSee('21,50')
        ->assertDontSee('10,50')
        ->assertSee('15. 3. 2026 12:50')
        ->assertDontSee('15. 3. 2026 12:30')
        ->assertSee('2150')
        ->assertDontSee('1050');

    expect(chartRows($component->html()))->toContain('21.5')->not->toContain('10.5');

    $component->set('sensor', 'second')
        ->assertSet('sensor', 'second')
        ->assertSee('10,50')
        ->assertDontSee('21,50')
        ->assertSee('15. 3. 2026 12:30')
        ->assertSee('1050')
        ->assertDontSee('2150');

    expect(chartRows($component->html()))->toContain('10.5')->not->toContain('21.5');
});

it('falls back to the first sensor when the link names one that is gone', function (): void {
    $sensor = Sensor::factory()->create(['name' => 'the-only-one']);

    Livewire::withQueryParams(['sensor' => 'the-other-one']);

    Livewire::test(Dashboard::class)
        ->assertSet('sensor', 'the-only-one')
        ->assertSee('the-only-one');
});

it('keeps another sensor\'s readings out of the averaging', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    $shown = Sensor::factory()->create();
    $other = Sensor::factory()->create();

    // The other station reads ten degrees colder in every bucket. Averaged
    // across the whole table, the shown sensor's line would sit five degrees
    // under what it measured.
    foreach (range(0, 3) as $bucket) {
        Measurement::factory()->for($other)->create([
            'timestamp' => $start->getTimestamp() + $bucket * 3600,
            'data' => (string) new MeasurementDataV1(temperature: 1000, humidity: 5000, pressure: 97389),
        ]);
        Measurement::factory()->for($shown)->create([
            'timestamp' => $start->getTimestamp() + $bucket * 3600 + 60,
            'data' => (string) new MeasurementDataV1(temperature: 2000, humidity: 5000, pressure: 97389),
        ]);
    }

    $month = filledBuckets(Livewire::test(Dashboard::class)
        ->call('zoomTo', $start->getTimestamp() - 20 * 86400, now()->getTimestamp())
        ->html());

    expect(array_column($month, 1))->toEqual([20, 20, 20, 20]);
});

it('marks only the selected sensor\'s events', function (): void {
    $shown = Sensor::factory()->create();
    $other = Sensor::factory()->create();

    StationEvent::factory()->for($shown)->create([
        'occurred_at' => Date::parse('2026-07-15 10:00:00', 'UTC'),
        'title' => 'Radiation shield fitted',
    ]);
    StationEvent::factory()->for($other)->create([
        'occurred_at' => Date::parse('2026-07-16 10:00:00', 'UTC'),
        'title' => 'Moved to the balcony',
    ]);

    $component = Livewire::test(Dashboard::class);

    expect(array_column(chartEvents($component->html()), 1))->toBe(['Radiation shield fitted']);

    // Switching sensors swaps the marks along with the readings.
    $component->set('sensor', $other->slug);

    expect(array_column(chartEvents($component->html()), 1))->toBe(['Moved to the balcony']);
});
