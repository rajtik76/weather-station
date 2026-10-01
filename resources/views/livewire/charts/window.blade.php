<section class="page-wrap pt-10 sm:pt-14" aria-labelledby="charts-h">
    <div class="flex flex-wrap items-end justify-between gap-x-10 gap-y-5">
        <div class="max-w-[60ch]">
            <h1 id="charts-h" class="m-0 font-display text-[30px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[38px]">Charts</h1>
            <p class="m-0 mt-3 text-[17px] leading-relaxed text-ink-2">Every channel on one shared timebase. Drag across a strip to zoom in, or move the window along the whole record above the strips.</p>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <p class="num m-0 font-mono text-[14px] whitespace-nowrap text-ink-3">
                <span class="sr-only">Range</span>
                {{ $this->window['from'] }} → {{ $this->window['to'] }}
                <span class="ml-3 text-ink-3">{{ \App\ValueObject\Figure::format($this->recordCount, 0) }} {{ $this->recordCount === 1 ? 'record' : 'records' }}</span>
            </p>
            {{-- Always rendered, only disabled, so the first zoom does not shift the row mid-click. --}}
            <button
                type="button"
                wire:click="resetZoom"
                @disabled(! $this->isZoomed)
                class="cursor-pointer rounded-md border border-line-2 bg-screen px-3.5 py-2 font-mono text-[14px] text-ink-2 hover:text-ink disabled:cursor-default disabled:opacity-50 disabled:hover:text-ink-2"
            >Reset zoom</button>
        </div>
    </div>
</section>
