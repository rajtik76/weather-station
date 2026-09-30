<x-tile
    title="Where it is"
    icon="map-pin"
    :tone="$tones['rain']"
    hint="Plzeň-Slovany, CZ, approximate location within {{ number_format($this->approximateLocation['radius']) }} m"
    aria-label="Station location"
    class="col-span-12"
>
    <div
        wire:ignore
        data-station-map
        data-lat="{{ $this->approximateLocation['lat'] }}"
        data-lng="{{ $this->approximateLocation['lng'] }}"
        data-radius="{{ $this->approximateLocation['radius'] }}"
        class="-mx-4 -mb-4 h-64 overflow-hidden rounded-b-[18px] sm:-mx-5 sm:-mb-[18px] sm:h-72 sm:rounded-b-[22px]"
        role="img"
        aria-label="Map showing the approximate area the station reports from"
    ></div>
</x-tile>
