{{-- One chart strip: a tile with its header, the legend (the slot), the fold and the ECharts canvas.
     The fold is Alpine state: a Livewire round trip would re-run every query to flip a class. --}}
@props([
    'key',
    'label',
    'height',
    'title',
    'icon',
    'tone',
    'hint' => null,
    'span' => 'col-span-12',
])

<x-tile
    :title="$title"
    :icon="$icon"
    :tone="$tone"
    :hint="$hint"
    aria-label="{{ $label }} history"
    class="{{ $span }}"
    x-data="{ collapsed: false }"
>
    <x-slot:actions>
        {{-- Folds the strip to its header; the canvas stays mounted, so the zoom and crosshair survive. --}}
        <x-fold-button controls="strip-{{ $key }}" :label="$label" />
    </x-slot:actions>

    <div class="flex flex-wrap items-center gap-2" x-bind:class="{ 'mb-2': ! collapsed }">
        {{ $slot }}
    </div>

    {{-- Hidden, not removed: ECharts keeps its instance and resizes once the box has a size again. --}}
    <div id="strip-{{ $key }}" x-bind:class="{ hidden: collapsed }">
        {{-- ECharts owns everything below; a morph would tear out the canvas.
             Touch pans only vertically, so a sideways finger drag selects a span to zoom into. --}}
        <div
            wire:ignore
            data-strip="{{ $key }}"
            class="relative {{ $height }} w-full cursor-crosshair touch-pan-y touch-pinch-zoom select-none"
        >
            <div data-canvas class="absolute inset-0"></div>
            <div
                data-zoom-band
                hidden
                aria-hidden="true"
                class="pointer-events-none absolute inset-y-0 border-x border-slate-900/40 bg-slate-900/10 dark:border-white/40 dark:bg-white/10"
            ></div>
        </div>
    </div>
</x-tile>
