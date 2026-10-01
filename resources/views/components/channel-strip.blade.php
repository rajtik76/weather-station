{{-- One chart strip in the instrument look: the channel's label and legend
     (the slot), the fold and the ECharts canvas. Every strip goes through it:
     station-charts.js finds the canvas by data-strip, and the fold is Alpine
     state, not a round trip. --}}
@props([
    'key',
    'label',
    'channel',
    'height',
])

<section aria-label="{{ $label }} history" x-data="{ collapsed: false }" {{ $attributes->class('border-t border-line pt-5 first:border-t-0 first:pt-0') }}>
    <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2" x-bind:class="{ 'mb-3': ! collapsed }">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 font-mono text-[13px]">
            <span class="text-ink">{{ $channel }}</span>
            {{ $slot }}
        </div>
        <button
            type="button"
            x-on:click="collapsed = ! collapsed"
            aria-controls="strip-{{ $key }}"
            x-bind:aria-expanded="collapsed ? 'false' : 'true'"
            aria-expanded="true"
            x-bind:aria-label="(collapsed ? 'Show ' : 'Hide ') + @js($label)"
            aria-label="Hide {{ $label }}"
            class="grid size-8 cursor-pointer place-items-center rounded-md text-ink-3 hover:text-ink"
        >
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" class="transition-transform" x-bind:class="{ '-rotate-90': collapsed }"><path d="M3 5.5 7 9.5 11 5.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
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
                class="pointer-events-none absolute inset-y-0 border-x border-ink/40 bg-ink/10"
            ></div>
        </div>
    </div>
</section>
