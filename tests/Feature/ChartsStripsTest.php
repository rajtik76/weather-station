<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Models\Measurement;
use App\ValueObject\MeasurementDataV1;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('keeps the dew point off until the reader asks for it', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $component = Livewire::test(Charts::class);

    // Derived, so off by default; the label is the switch.
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

    $component = Livewire::test(Charts::class)
        ->call('toggleChannel', 't');

    // The last line on cannot be switched off.
    expect($component->html())
        ->toContain('data-hidden-channels="[&quot;t&quot;,&quot;d&quot;]"')
        ->toMatch('/toggleChannel\(\'h\'\)"[^>]*disabled/')
        ->not->toMatch('/toggleChannel\(\'t\'\)"[^>]*disabled/');

    expect($component->call('toggleChannel', 'h')->html())
        ->toContain('data-hidden-channels="[&quot;t&quot;,&quot;d&quot;]"');

    expect($component->call('toggleChannel', 'd')->call('toggleChannel', 'h')->html())
        ->toContain('data-hidden-channels="[&quot;t&quot;,&quot;h&quot;]"');
});

it('ignores a switch for a channel the strip does not have', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    expect(Livewire::test(Charts::class)->call('toggleChannel', 'p')->html())
        ->toContain('data-hidden-channels="[&quot;d&quot;]"');
});

it('renders every strip open with a client-side fold', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    // Alpine hides the wrapper, never removes it: the canvas stays for ECharts to resize.
    expect(Livewire::test(Charts::class)->html())
        ->toMatch('/aria-controls="strip-p"[^>]*aria-expanded="true"/')
        ->toContain('<div id="strip-p" x-bind:class="{ hidden: collapsed }">')
        ->toContain('data-strip="p"')
        ->not->toContain('wire:click="toggleStrip');
});

it('leaves sideways touch drags on a strip to the zoom selection', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    // The page still scrolls and pinches over a chart; only the horizontal pan is taken.
    expect(Livewire::test(Charts::class)->html())
        ->toMatch('/data-strip="th"\s+class="[^"]*touch-pan-y touch-pinch-zoom/');
});

it('draws temperature and humidity on one strip and pressure on another', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Charts::class)->html();

    // Two canvases, three headers.
    expect(substr_count($html, 'data-canvas'))->toBe(2)
        ->and(substr_count($html, 'data-strip="th"'))->toBe(1)
        ->and(substr_count($html, 'data-strip="p"'))->toBe(1)
        ->and($html)->toContain('Temperature, °C')
        ->toContain('Humidity, %')
        ->toContain('Pressure, MSL, hPa')
        // The tail follows the charts.
        ->and(Str::after($html, 'data-canvas'))->toContain('when they arrived')
        ->and(Str::before($html, 'data-canvas'))->not->toContain('when they arrived');
});

it('puts the navigator above the strips it scrolls', function (): void {
    Measurement::factory()->create(['timestamp' => now()->subMinutes(10)->getTimestamp()]);

    $html = Livewire::test(Charts::class)->html();

    // Matched on the section label; `data-navigator-rows` would otherwise hit first.
    expect(Str::before($html, 'Whole record'))->toContain('Range')
        ->not->toContain('data-strip=')
        ->and(Str::after($html, 'Whole record'))->toContain('data-strip="th"');
});

it('labels the shared strip with each channel and its unit', function (): void {
    Measurement::factory()->create([
        'timestamp' => now()->subMinutes(10)->getTimestamp(),
        'data' => (string) new MeasurementDataV1(temperature: 2150, humidity: 4800, pressure: 97389),
    ]);

    $html = Livewire::test(Charts::class)->html();

    // Pinned between the payload and the canvas; the transmissions print the same figures below.
    $headers = Str::before(Str::after($html, 'data-chart-rows'), 'aria-label="Pressure, MSL history"');

    expect($headers)->toContain('Temperature, °C')
        ->toContain('Dew point, °C')
        ->toContain('Humidity, %')
        // Labels only.
        ->not->toContain('21,50')
        ->not->toContain('48,00')
        // Pressure heads its own strip.
        ->not->toContain('Pressure, MSL');
});
