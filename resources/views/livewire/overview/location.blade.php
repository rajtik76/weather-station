{{-- Public page: a blurred circle, no marker, no exact spot. --}}
@php($location = $this->approximateLocation)
<x-horizon.section aria-labelledby="where-h">
    <x-horizon.heading id="where-h">Where it is</x-horizon.heading>
    <p class="hz-lede">Plzeň-Slovany, within {{ \App\ValueObject\Figure::format($location['radius'], 0) }} m.</p>
    <div class="hz-card mt-10 overflow-hidden p-0!">
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
</x-horizon.section>
