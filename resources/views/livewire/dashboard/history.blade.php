<x-tile
    title="History"
    icon="clock"
    aria-label="Chart range"
    class="col-span-12"
>
    <x-slot:actions>
        <p class="font-mono text-xs whitespace-nowrap text-slate-500 tabular-nums dark:text-slate-400">
            <span class="sr-only">Range</span>
            {{ $this->window['from'] }} → {{ $this->window['to'] }}
        </p>
        {{-- Always rendered, only disabled, so the first zoom does not shift the row mid-click. --}}
        <flux:button
            wire:click="resetZoom"
            :disabled="! $this->isZoomed"
            variant="subtle"
            size="sm"
            icon="arrow-path"
        >Reset zoom</flux:button>
    </x-slot:actions>

    {{-- Above the strips: below them a drag moved a chart that was off screen. --}}
    <section aria-label="Whole record">
        <div
            wire:ignore
            data-navigator
            class="h-20 w-full"
            role="img"
            aria-label="The whole record, with the shown window marked. Drag its edges to move through time."
        ></div>
    </section>
</x-tile>
