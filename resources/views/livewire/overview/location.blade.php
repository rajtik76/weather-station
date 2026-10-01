{{-- Where the station is, blurred into a circle: the page is public, so no
     marker and no exact spot (station-map.js). --}}
@php($location = $this->approximateLocation)
<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="where-h">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-1">
        <h2 id="where-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Where it is</h2>
        <p class="num m-0 font-mono text-[13px] text-ink-3">Plzeň-Slovany · within {{ \App\ValueObject\Figure::format($location['radius'], 0) }} m</p>
    </div>
    <div class="overflow-hidden rounded-[10px] border border-line bg-screen">
        <div
            wire:ignore
            data-station-map
            data-lat="{{ $location['lat'] }}"
            data-lng="{{ $location['lng'] }}"
            data-radius="{{ $location['radius'] }}"
            class="h-64 sm:h-80"
            role="img"
            aria-label="Map showing the approximate area the station reports from"
        ></div>
    </div>
</section>
