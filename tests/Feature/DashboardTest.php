<?php

declare(strict_types=1);

use App\Enums\ProtocolVersion;
use App\Livewire\Dashboard;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
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
 * The navigator's own payload, which always spans the whole record.
 *
 * @return list<array{0: int, 1: float, 2: float, 3: float, 4: int}>
 */
function navigatorRows(string $html): array
{
    preg_match('/data-navigator-rows="([^"]*)"/', $html, $matches);

    return json_decode(html_entity_decode($matches[1] ?? '[]'), true);
}

it('renders the readings stored in the database', function (): void {
    $at = now()->subHour();

    Measurement::factory()->create([
        'sensor_name' => 'bme280',
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

    Measurement::factory()->create(['timestamp' => now()->subHour()->getTimestamp()]);
    Measurement::factory()->create(['timestamp' => now()->subDays(10)->getTimestamp()]);

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

it('thins long ranges rather than averaging them', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    // A full day at the station's ten-minute cadence.
    foreach (range(0, 143) as $slot) {
        Measurement::factory()->create(['timestamp' => $start->getTimestamp() + $slot * 600]);
    }

    // A window wide enough to be thinned, against one that is not.
    $year = chartRows(Livewire::test(Dashboard::class)
        ->call('zoomTo', $start->getTimestamp() - 300 * 86400, now()->getTimestamp())
        ->html());

    $week = chartRows(Livewire::test(Dashboard::class)->html());

    // One reading kept per six hours, and each one is a stored record.
    expect($year)
        ->toContain('1772326800000')
        ->toContain('1772348400000')
        ->toContain('1772370000000')
        ->toContain('1772391600000')
        // The next reading shares that bucket, so it is dropped outright
        // rather than averaged into the point that survives.
        ->not->toContain('1772327400000')
        ->and($week)->toContain('1772327400000');
});

it('thins a window whose stamps never land near a bucket boundary', function (): void {
    $start = Date::parse('2026-03-01 00:00:00', 'UTC');
    $this->travelTo($start->copy()->addDay());

    // The same day, but stamped fifteen minutes off every slot - the drift a
    // station accumulates by uploading when it wakes. Thinning by the phase of
    // the epoch found no row at all here and emptied the chart.
    foreach (range(0, 143) as $slot) {
        Measurement::factory()->create(['timestamp' => $start->getTimestamp() + $slot * 600 + 900]);
    }

    $year = chartRows(Livewire::test(Dashboard::class)
        ->call('zoomTo', $start->getTimestamp() - 300 * 86400, now()->getTimestamp())
        ->html());

    // One point per six hours, each the bucket's own first reading. The
    // buckets divide the epoch rather than the local day, so the first one
    // opens with the day's first upload and the rest fall six hours apart.
    expect($year)
        ->toContain('1772327700000')
        ->toContain('1772348700000')
        ->toContain('1772370300000')
        ->toContain('1772391900000')
        // The next reading shares the first bucket and is dropped.
        ->not->toContain('1772328300000');
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

it('narrows the window to a dragged selection', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subHour()->getTimestamp()]);
    Measurement::factory()->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

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
        ->assertSee('Last measurement')
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

    foreach ($packets as $packet) {
        Measurement::factory()->create([
            'timestamp' => now()->subMinutes($packet['ago'])->getTimestamp(),
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
        ->assertSee('2134')
        ->assertSee('5812')
        ->assertSee('97389')
        ->assertSee('2112')
        ->assertSee('2087')
        // Converted alongside: 97 389 Pa read at 345 m reduces to 1013,5 hPa at
        // sea level, stamped in Prague time - 11:50 UTC is 12:50 there in March.
        ->assertSee('1 013,5')
        ->assertSee('15. 3. 2026 12:50')
        // A fourth packet, and the oldest of them, has scrolled off the tail.
        ->assertDontSee('1901');
});

it('draws temperature and humidity on one strip and pressure on another', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Dashboard::class)->html();

    // Two canvases, three headers: the shared strip reports each channel
    // against its own unit above the one grid they are drawn on.
    expect(substr_count($html, 'data-canvas'))->toBe(2)
        ->and(substr_count($html, 'data-strip="th"'))->toBe(1)
        ->and(substr_count($html, 'data-strip="p"'))->toBe(1)
        ->and($html)->toContain('Temperature · °C')
        ->toContain('Humidity · %')
        ->toContain('Pressure, MSL · hPa')
        // The payload tail reads as a footnote to the charts, so it follows them.
        ->and(Str::after($html, 'data-canvas'))->toContain('as received')
        ->and(Str::before($html, 'data-canvas'))->not->toContain('as received');
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

it('heads the shared strip with a window summary per channel', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $html = Livewire::test(Dashboard::class)->html();

    // The hero above also prints the current reading, so the assertion is
    // pinned to the band between the chart payload and the strip's canvas.
    $headers = Str::before(Str::after($html, 'data-chart-rows'), 'data-strip="th"');

    expect($headers)->toContain('Temperature · °C')
        ->toContain('Humidity · %')
        ->toContain('21,50')
        ->toContain('48,00')
        // Pressure is headed above its own strip, not this one.
        ->not->toContain('Pressure, MSL');
});

it('draws the navigator for a record shorter than one thinning bucket', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // Three hours of uploads, stamped when the station woke rather than on the
    // slot - the drift is what a real ESP32 sends. A whole record this short
    // holds no row at all near a six-hour boundary, and thinning by the phase
    // of the epoch left the navigator with nothing to draw.
    foreach (range(1, 18) as $slot) {
        Measurement::factory()->create([
            'timestamp' => now()->subMinutes($slot * 10)->getTimestamp() + 122,
        ]);
    }

    expect(navigatorRows(Livewire::test(Dashboard::class)->html()))->toHaveCount(18);
});

it('thins the navigator to one point per bucket once the record is long', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $data = (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389);

    // Eleven days of ten-minute uploads, drifting off the slot as above. That
    // is 44 six-hour buckets, and the navigator keeps the first row of each.
    $rows = collect(range(1, 1584))->map(fn (int $slot): array => [
        'sensor_name' => 'bme280',
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
