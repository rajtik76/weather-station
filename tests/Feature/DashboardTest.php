<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;
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

it('still reports the last transmission when the window holds nothing', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

    Livewire::test(Dashboard::class)
        ->call('zoomTo', now()->subHour()->getTimestamp(), now()->getTimestamp())
        ->assertSee('Last transmission')
        ->assertSee('Nothing in this range');
});

it('calls the station silent after three missed slots', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subMinutes(20)->getTimestamp()]);
    Livewire::test(Dashboard::class)->assertSee('Station live');

    Measurement::query()->delete();

    Measurement::factory()->create(['timestamp' => now()->subMinutes(40)->getTimestamp()]);
    Livewire::test(Dashboard::class)->assertSee('Station silent');
});

it('credits the author in the footer', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Vladislav Rajtmajer')
        ->assertSee((string) now()->year)
        ->assertSee('https://github.com/rajtik76');
});
