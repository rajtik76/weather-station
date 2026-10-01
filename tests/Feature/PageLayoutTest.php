<?php

declare(strict_types=1);

use App\Livewire\Overview;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

it('describes the page for search results', function (): void {
    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('<meta name="description" content="A balcony weather station that forecasts its own next six hours', escape: false);
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

it('credits the author, the forecast data and the source in the footer', function (): void {
    // 23:30 UTC on New Year's Eve is already the new year in Prague.
    $this->travelTo(Date::parse('2026-12-31 23:30:00', 'UTC'));

    $this->get(route('overview'))
        ->assertOk()
        ->assertSee('© 2027 <a class="link-ink" href="https://rajtik.com">Vladislav Rajtmajer</a>', false)
        ->assertSee('ČHMÚ')
        ->assertSee('CC BY 4.0')
        ->assertSee('https://github.com/rajtik76/weather-station');
});
