<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('ignores readings older than the window', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subDays(40)->getTimestamp(),
    ]);

    $this->get(route('charts'))->assertSee('Nothing in this range');
});

it('opens on the last week', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::test(Charts::class)
        ->assertSet('from', null)
        ->assertSet('to', null)
        ->assertSee('8.3.2026 13:00 → 15.3.2026 13:00');
});

it('takes the window from the query string', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::withQueryParams([
        'from' => Date::parse('2026-03-10 00:00:00', 'UTC')->getTimestamp(),
        'to' => Date::parse('2026-03-12 00:00:00', 'UTC')->getTimestamp(),
    ]);

    Livewire::test(Charts::class)->assertSee('10.3.2026 01:00 → 12.3.2026 01:00');
});

it('plots only the readings inside the window', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subHour()->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subDays(10)->getTimestamp()]);

    // CET in March: payload stamps carry the +1 h.
    $recent = '1773576000000';
    $older = '1772715600000';

    $wide = chartRows(Livewire::test(Charts::class)
        ->call('zoomTo', now()->subDays(20)->getTimestamp(), now()->getTimestamp())
        ->html());

    $narrow = chartRows(Livewire::test(Charts::class)
        ->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp())
        ->html());

    expect($narrow)->toContain($recent)->not->toContain($older)
        ->and($wide)->toContain($recent)->toContain($older);
});

it('widens the buckets with the window', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->subMinutes(20)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2000, humidity: 5000, pressure: 97389),
    ]);
    Measurement::factory()->for($sensor)->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2200, humidity: 5000, pressure: 97389),
    ]);

    // A week is half-hour buckets: the pair averages into one point.
    $week = filledBuckets(Livewire::test(Charts::class)->html());

    expect($week)->toHaveCount(1)
        ->and(array_key_first($week))->toBe(now()->subMinutes(30)->getTimestamp())
        ->and(array_values($week)[0][1])->toEqual(21);

    $hour = filledBuckets(Livewire::test(Charts::class)
        ->call('zoomTo', now()->subHour()->getTimestamp(), now()->getTimestamp())
        ->html());

    expect(array_column($hour, 1))->toEqual([20, 22]);
});

it('picks the bucket width from the span on screen', function (int $days, int $bucketSeconds, array $epochs): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    foreach (range(0, 5) as $slot) {
        Measurement::factory()->for($sensor)->create([
            'timestamp' => now()->subHour()->getTimestamp() + $slot * 600,
            'data' => (string) new MeasurementDataV1(temperature: 1000 + $slot * 100, humidity: 5000, pressure: 97389),
        ]);
    }

    $html = Livewire::test(Charts::class)
        ->call('zoomTo', now()->subDays($days)->getTimestamp(), now()->getTimestamp())
        ->html();

    $rows = bucketRows($html);
    $filled = filledBuckets($html);

    expect($rows)->toHaveCount(intdiv($days * 86400, $bucketSeconds) + 1)
        ->and(array_column($rows, 5))->each->toBeIn(range($rows[0][5], now()->getTimestamp(), $bucketSeconds))
        ->and(array_keys($filled))->toBe(array_map(fn (int $epoch): int => now()->getTimestamp() + $epoch, $epochs));
})->with([
    '7 days' => [7, 1800, [-3600, -1800]],
    '14 days' => [14, 3600, [-3600]],
    '1 month' => [30, 3600, [-3600]],
]);

it('draws no wider than a month', function (int $days): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $component = Livewire::test(Charts::class)
        ->call('zoomTo', now()->subDays($days)->getTimestamp(), now()->getTimestamp())
        ->assertSet('to', now()->getTimestamp())
        ->assertSet('from', now()->getTimestamp() - 30 * 86400)
        ->assertSee('13.2.2026 13:00 → 15.3.2026 13:00');

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

    Livewire::test(Charts::class)
        ->assertSet('to', Date::parse('2026-03-10 12:00:00', 'UTC')->getTimestamp())
        ->assertSet('from', Date::parse('2026-02-08 12:00:00', 'UTC')->getTimestamp());
});
it('narrows the window to a dragged selection', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subHour()->getTimestamp()]);
    Measurement::factory()->for($sensor)->create(['timestamp' => now()->subDays(3)->getTimestamp()]);

    $recent = '1773576000000';
    $older = '1773320400000';

    $component = Livewire::test(Charts::class);

    expect(chartRows($component->html()))->toContain($recent)->toContain($older);

    // Real epochs, not the shifted stamps.
    $component->call('zoomTo', now()->subHours(2)->getTimestamp(), now()->getTimestamp());

    expect(chartRows($component->html()))->toContain($recent)->not->toContain($older);

    $component->call('resetZoom');

    expect(chartRows($component->html()))->toContain($older);
});

it('orders and widens a backwards or tiny selection', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::test(Charts::class)
        ->call('zoomTo', now()->getTimestamp(), now()->subMinutes(5)->getTimestamp())
        ->assertSet('to', now()->getTimestamp())
        ->assertSet('from', now()->getTimestamp() - 2400);
});

it('normalises a window set without zoomTo', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    // One end alone falls back to the default rather than reaching back to 1970.
    Livewire::test(Charts::class)
        ->set('from', 0)
        ->assertSet('from', null)
        ->assertSet('to', null);

    Livewire::test(Charts::class)
        ->call('zoomTo', now()->subDay()->getTimestamp(), now()->getTimestamp())
        ->set('from', 0)
        ->assertSet('to', now()->getTimestamp())
        ->assertSet('from', now()->getTimestamp() - 30 * 86400);
});

it('refuses a window from the future', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::withQueryParams([
        'from' => now()->addDay()->getTimestamp(),
        'to' => now()->addDays(2)->getTimestamp(),
    ]);

    Livewire::test(Charts::class)->assertSet('to', now()->getTimestamp());
});

it('names the window it is showing', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Livewire::test(Charts::class)
        ->call('zoomTo', now()->subDay()->getTimestamp(), now()->getTimestamp())
        // 12:00 UTC is 13:00 in Prague, and a day-wide window names the clock.
        ->assertSee('14.3.2026 13:00 → 15.3.2026 13:00');
});
