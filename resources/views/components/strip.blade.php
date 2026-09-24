{{-- One chart strip: its header (the slot), the fold and the ECharts canvas.
     The fold is Alpine state: a Livewire round trip would re-run every query to flip a class. --}}
@props([
    'key',
    'label',
    'height',
])

<section
    aria-label="{{ $label }} history"
    class="border-b border-zinc-900/10 dark:border-white/10"
    x-data="{ collapsed: false }"
>
    <div
        class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 pt-5 pb-2 sm:px-8"
        x-bind:class="{ 'pb-2': ! collapsed, 'pb-5': collapsed }"
    >
        {{ $slot }}
        {{-- Folds the strip to its header; the canvas stays mounted, so the zoom and crosshair survive. --}}
        <x-fold-button controls="strip-{{ $key }}" :label="$label" />
    </div>

    {{-- Hidden, not removed: ECharts keeps its instance and resizes once the box has a size again. --}}
    <div id="strip-{{ $key }}" x-bind:class="{ hidden: collapsed }">
        {{-- ECharts owns everything below; a morph would tear out the canvas. --}}
        <div
            wire:ignore
            data-strip="{{ $key }}"
            class="relative {{ $height }} w-full cursor-crosshair select-none"
        >
            <div data-canvas class="absolute inset-0"></div>
            <div
                data-zoom-band
                hidden
                aria-hidden="true"
                class="pointer-events-none absolute inset-y-0 border-x border-zinc-900/40 bg-zinc-900/10 dark:border-white/40 dark:bg-white/10"
            ></div>
        </div>
    </div>
</section>
