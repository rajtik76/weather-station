<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\Models\Sensor;
use App\ValueObject\LocalTime;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

it('keeps the noise strips over a window before the sensor had noise', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    $sensor = Sensor::factory()->create();

    Measurement::factory()->for($sensor)->v2()->create(['timestamp' => now()->subHours(3)->getTimestamp()]);
    Measurement::factory()->for($sensor)->v3()->create(['timestamp' => now()->subMinutes(10)->getTimestamp(), 'data' => (string) noisyWindow(5000, 5500, 4500, 6000, 3000)]);

    $html = Livewire::withQueryParams([
        'from' => now()->subHours(4)->getTimestamp(),
        'to' => now()->subHours(2)->getTimestamp(),
    ])->test(Charts::class)->html();

    expect(noiseBuckets($html))->toBe([])
        ->and($html)->toContain('Noise spectrum history');
});

it('draws no noise strips for a sensor that never had noise', function (): void {
    $this->travelTo(Date::parse('2026-03-15 12:00:00', 'UTC'));

    Measurement::factory()->v2()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Charts::class)->html();

    expect(noiseBuckets($html))->toBe([])
        ->and($html)->not->toContain('Noise spectrum history');
});

it('averages the noise in a bucket as energy', function (): void {
    $start = Date::parse('2026-03-15 10:00:00', 'UTC');
    $this->travelTo($start->copy()->addHours(2));

    $sensor = Sensor::factory()->create();

    // Two windows in one half-hour bucket of the week view.
    foreach ([[5000, 5500, 4500, 6000, 3000], [6000, 6500, 5500, 7000, 4000]] as $slot => [$laeq, $la10, $la90, $lamax, $band]) {
        Measurement::factory()->for($sensor)->v3()->create([
            'timestamp' => $start->getTimestamp() + $slot * 600,
            'data' => (string) noisyWindow($laeq, $la10, $la90, $lamax, $band),
        ]);
    }

    $html = Livewire::test(Charts::class)->html();
    $row = noiseBuckets($html)[$start->getTimestamp()];

    // 50 and 60 dB are 57.4 together; the percentiles only average, LAmax takes the louder.
    expect(array_slice($row, 2, 4))->toEqual([57.4, 60.0, 50.0, 70.0])
        ->and(array_values(array_unique(array_slice($row, 6))))->toEqual([37.4])
        ->and(count($row))->toBe(6 + 26)
        ->and($html)->toContain('Noise spectrum history');
});

it('weights the noise in a bucket by the seconds each window heard', function (): void {
    $start = Date::parse('2026-03-15 10:00:00', 'UTC');
    $this->travelTo($start->copy()->addHours(2));

    $sensor = Sensor::factory()->create();

    // A full window at 50 dB, and half a minute after a boot at 80 dB.
    foreach ([[5000, 5500, 4500, 6000, 3000, 600], [8000, 8500, 7500, 9000, 6000, 30]] as $slot => [$laeq, $la10, $la90, $lamax, $band, $seconds]) {
        Measurement::factory()->for($sensor)->v3()->create([
            'timestamp' => $start->getTimestamp() + $slot * 600,
            'data' => (string) noisyWindow($laeq, $la10, $la90, $lamax, $band, $seconds),
        ]);
    }

    $row = noiseBuckets(Livewire::test(Charts::class)->html())[$start->getTimestamp()];

    // Not 77.0: the loud half minute is a twentieth of what the bucket heard.
    expect(array_slice($row, 2, 4))->toEqual([66.9, 56.4, 46.4, 90.0])
        ->and(array_values(array_unique(array_slice($row, 6))))->toEqual([46.9]);
});

it('folds the noise strips like the others', function (): void {
    Measurement::factory()->v3()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) noisyWindow(5000, 5500, 4500, 6000, 3000),
    ]);

    expect(Livewire::test(Charts::class)->html())
        ->toMatch('/aria-controls="strip-spectrum"[^>]*aria-label="Hide Noise spectrum"/')
        ->toContain('<div id="strip-spectrum" x-bind:class="{ hidden: collapsed }">')
        ->toContain('data-strip="noise"')
        ->toContain('data-spectrum-scale');
});

it('marks the waterfall slots the microphone heard rain in', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    // On the hour and half past: slot starts at any bucket width the default range picks.
    $rain = now()->subMinutes(60)->getTimestamp();
    $wetRoad = now()->subMinutes(30)->getTimestamp();

    foreach ([$rain => spectrumWindow(57.6, 4.7), $wetRoad => spectrumWindow(50.8, 0.6)] as $timestamp => $window) {
        Measurement::factory()->for($sensor)->v3()->create(['timestamp' => $timestamp, 'data' => (string) $window]);
    }

    // The slot's wall-clock ms, as the waterfall's cells are placed, and its epoch.
    Livewire::test(Charts::class)->assertSet('rainSlots', [[LocalTime::of($rain)->wallClockMs(), $rain]]);
});

it('keeps a shower on a wide bucket among dry windows', function (): void {
    $this->travelTo(Date::parse('2026-09-24 08:00:00', 'UTC'));
    $sensor = Sensor::factory()->create();
    // One bucket of the month view: an hour.
    $hour = now()->subHours(2)->getTimestamp();

    // Traffic loud around 1 kHz in the dry windows: averaged in, it would drown the shield's ring.
    foreach (range(0, 5) as $slot) {
        Measurement::factory()->for($sensor)->v3()->create([
            'timestamp' => $hour + $slot * 600,
            'data' => (string) ($slot === 2 ? spectrumWindow(57.6, 4.7) : spectrumWindow(30.0, 0.0, 50.0)),
        ]);
    }

    Livewire::test(Charts::class, ['from' => now()->subDays(20)->getTimestamp(), 'to' => now()->getTimestamp()])
        ->assertSet('rainSlots', [[LocalTime::of($hour)->wallClockMs(), $hour]]);
});
