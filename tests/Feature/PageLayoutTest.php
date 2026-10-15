<?php

declare(strict_types=1);

use App\Livewire\Overview;
use App\Models\Sensor;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

it('describes the page for search results', function (): void {
    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('<meta name="description" content="Temperature, humidity and pressure from a balcony in Plzeň.', escape: false);
});

it('loads the bundled font faces', function (): void {
    $this->partialMock(Vite::class)
        ->shouldReceive('fonts')
        ->once()
        ->andReturn(new HtmlString('<style id="bundled-fonts"></style>'));

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('<style id="bundled-fonts"></style>', escape: false);
});

it('serves the station mark as the favicon', function (): void {
    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('href="/favicon.svg"', escape: false)
        ->assertSee('href="/favicon.ico"', escape: false);

    expect(public_path('favicon.svg'))->toBeReadableFile()
        ->and(public_path('favicon.ico'))->toBeReadableFile()
        ->and(public_path('apple-touch-icon.png'))->toBeReadableFile();
});

it('polls for readings that arrive while the page is open', function (): void {
    Livewire::test(Overview::class)->assertSee('wire:poll.60s', escape: false);
});

it('credits the author, the reference data and the source in the footer', function (): void {
    $this->travelTo(Date::parse('2026-12-31 23:30:00', 'UTC'));

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('© 2027 <a class="link-ink" href="https://rajtik.com">Vladislav Rajtmajer</a>', false)
        ->assertSee('ČHMÚ')
        ->assertSee('CC BY 4.0')
        ->assertSee('https://github.com/rajtik76/weather-station');
});

it('returns 404 for the retired forecast page', function (): void {
    $this->get('/forecast')->assertNotFound();
});

it('links the remaining pages without offering forecasts', function (string $route, bool $hasSensorChoice): void {
    $query = [];

    if ($hasSensorChoice) {
        Sensor::factory()->create(['name' => 'first']);
        $selectedSensor = Sensor::factory()->create(['name' => 'second']);
        $query = ['sensor' => $selectedSensor->slug];
    }

    $this->get(route($route, $query))
        ->assertOk()
        ->assertSee('href="'.route('overview', $query).'"', false)
        ->assertSee('href="'.route('charts', $query).'"', false)
        ->assertDontSee('/forecast', false)
        ->assertDontSee('id="fc-h"', false)
        ->assertDontSee('id="verdict-h"', false)
        ->assertDontSee('Next six hours');
})->with([
    'overview' => ['overview', false],
    'charts' => ['charts', false],
    'overview with selected sensor' => ['overview', true],
    'charts with selected sensor' => ['charts', true],
]);
