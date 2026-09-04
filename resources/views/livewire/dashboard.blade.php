<div class="min-h-screen bg-zinc-50 font-sans text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">

    {{-- ── Top bar ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between gap-4 border-b border-zinc-900/10 px-4 py-3 font-mono text-[11px] font-medium tracking-[0.25em] text-zinc-500 uppercase sm:px-8 dark:border-white/10 dark:text-zinc-400">
        <span>Station log · ESP32 + BME280</span>
        <span class="flex items-center gap-2 normal-case tracking-normal">
            <span
                @class([
                    'size-1.5 shrink-0 rounded-full',
                    'bg-emerald-500' => ! $this->isSilent,
                    'bg-amber-500' => $this->isSilent,
                ])
                aria-hidden="true"
            ></span>
            {{-- Colour alone carries the link state, so name it for screen readers. --}}
            <span class="sr-only">{{ $this->isSilent ? 'Station silent' : 'Station live' }}</span>
            @if ($this->lastTransmission)
                <span class="tracking-[0.25em] uppercase">Last transmission</span>
                <span class="text-zinc-800 tabular-nums dark:text-zinc-200">{{ $this->measuredAt }}</span>
                <span class="hidden sm:inline">({{ $this->measuredAgo }})</span>
            @else
                <span class="tracking-[0.25em] uppercase">No transmission yet</span>
            @endif
        </span>
        <flux:button
            x-data
            x-on:click="$flux.dark = ! $flux.dark"
            variant="subtle"
            size="sm"
            icon="moon"
            aria-label="Toggle dark mode"
        />
    </div>

    {{-- ── Hero: title + giant readouts ───────────────────────────── --}}
    <header class="border-b border-zinc-900/10 px-4 pt-12 pb-10 sm:px-8 dark:border-white/10">
        <div class="flex flex-wrap items-end justify-between gap-x-16 gap-y-10">
            <div>
                <flux:heading level="1" class="font-display text-[clamp(3.5rem,12.5vw,11.5rem)]! leading-[0.78] font-extrabold! tracking-[-0.03em] uppercase">
                    Home<br>Weather<br>Station
                </flux:heading>
                <flux:text class="mt-6 max-w-sm text-sm">
                    The station reports every ten minutes, around the clock. Every
                    point on the chart is a raw record - nothing smoothed, nothing
                    averaged. The longer ranges thin the series out rather than
                    average it, so what you see stays a real reading.
                </flux:text>
            </div>

            @if ($this->hasReadings)
            <div class="flex flex-col items-start gap-8 lg:items-end lg:text-right" aria-label="Current conditions">
                @foreach ([
                    ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 2, 'accent' => 'text-amber-600'],
                    ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 2, 'accent' => 'text-cyan-600'],
                    ['key' => 'p', 'label' => 'Pressure', 'unit' => 'hPa', 'dec' => 1, 'accent' => 'text-violet-600 dark:text-violet-500'],
                ] as $readout)
                    @php($m = $this->metrics[$readout['key']])
                    <div>
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase lg:justify-end dark:text-zinc-400">
                            {{ $readout['label'] }}
                            <flux:icon
                                :icon="$m['delta'] >= 0.05 ? 'arrow-trending-up' : ($m['delta'] <= -0.05 ? 'arrow-trending-down' : 'minus')"
                                variant="micro"
                            />
                            {{ ($m['delta'] >= 0 ? '+' : '−') . number_format(abs($m['delta']), $readout['dec'], ',', ' ') }}/h
                        </p>
                        <p class="font-display mt-1 text-6xl leading-none font-bold sm:text-7xl">
                            {{ number_format($m['now'], $readout['dec'], ',', ' ') }}<span class="{{ $readout['accent'] }} ml-1 align-baseline text-2xl font-bold sm:text-3xl">{{ $readout['unit'] }}</span>
                        </p>
                        <p class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                            24 h · min {{ number_format($m['dayMin'], $readout['dec'], ',', ' ') }} · max {{ number_format($m['dayMax'], $readout['dec'], ',', ' ') }}
                        </p>
                    </div>
                @endforeach
            </div>
            @endif
        </div>
    </header>

    {{-- ── Range switcher ─────────────────────────────────────────── --}}
    <section
        aria-label="Chart range"
        class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b border-zinc-900/10 px-4 py-4 sm:px-8 dark:border-white/10"
    >
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                Range
            </p>
            <p class="font-mono text-xs whitespace-nowrap text-zinc-500 tabular-nums dark:text-zinc-400">
                {{ $this->window['from'] }} → {{ $this->window['to'] }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            {{-- Always rendered, only disabled: appearing on the first zoom
                 would widen this right-aligned group and shift what sits
                 beside it out from under the pointer mid-click. --}}
            <flux:button
                wire:click="resetZoom"
                :disabled="! $this->isZoomed"
                variant="subtle"
                size="sm"
            >Reset zoom</flux:button>

        </div>
    </section>

    {{-- ── Navigator ──────────────────────────────────────────────── --}}
    <section
        aria-label="Whole record"
        class="border-b border-zinc-900/10 px-4 pb-3 sm:px-8 dark:border-white/10"
    >
        <div
            wire:ignore
            data-navigator
            class="h-20 w-full"
            role="img"
            aria-label="The whole record, with the shown window marked. Drag its edges to move through time."
        ></div>
    </section>

    {{-- ── Channel strips ─────────────────────────────────────────── --}}
    @unless ($this->hasReadings)
        <section aria-label="No data" class="border-b border-zinc-900/10 px-4 py-12 text-center sm:px-8 dark:border-white/10">
            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                Nothing in this range
            </p>
            <flux:text class="mx-auto mt-4 max-w-sm text-sm">
                No reading was recorded between {{ $this->window['from'] }} and
                {{ $this->window['to'] }}. Pick a wider range, or reset the zoom.
            </flux:text>
        </section>
    @endunless

    @php($channels = [
        ['channel' => 'CH1', 'key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 2, 'height' => 'h-72 sm:h-80'],
        ['channel' => 'CH2', 'key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 2, 'height' => 'h-48 sm:h-56'],
        ['channel' => 'CH3', 'key' => 'p', 'label' => 'Pressure', 'unit' => 'hPa', 'dec' => 1, 'height' => 'h-48 sm:h-56'],
    ])

    {{-- The chart payload. station-charts.js watches these attributes, which is
         how a new window reaches canvases that Livewire must not touch. --}}
    <div
        data-chart-rows="{{ json_encode($this->readings) }}"
        data-navigator-rows="{{ json_encode($this->overview) }}"
        data-window-from="{{ $this->windowMs['from'] }}"
        data-window-to="{{ $this->windowMs['to'] }}"
        data-chart-component="{{ $this->getId() }}"
        hidden
    ></div>

    @foreach ($channels as $strip)
        @php($m = $this->metrics[$strip['key']] ?? null)
        <section
            aria-label="{{ $strip['label'] }} history"
            class="border-b border-zinc-900/10 dark:border-white/10"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-5 pb-2 sm:px-8">
                <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                    {{ $strip['channel'] }} · {{ $strip['label'] }} · {{ $strip['unit'] }}
                </p>
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($m)
                        window · min {{ number_format($m['min'], $strip['dec'], ',', ' ') }}
                        · max {{ number_format($m['max'], $strip['dec'], ',', ' ') }}
                        · avg {{ number_format($m['avg'], $strip['dec'], ',', ' ') }}
                    @else
                        no readings in this window
                    @endif
                </p>
            </div>

            {{-- `wire:ignore` because ECharts owns everything below this point;
                 a morph would tear the canvas out from under it. --}}
            <div
                wire:ignore
                data-channel="{{ $strip['key'] }}"
                class="relative {{ $strip['height'] }} w-full cursor-crosshair select-none"
            >
                <div data-canvas class="absolute inset-0"></div>
                <div
                    data-zoom-band
                    hidden
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-y-0 border-x border-zinc-900/40 bg-zinc-900/10 dark:border-white/40 dark:bg-white/10"
                ></div>
            </div>
        </section>
    @endforeach

    {{-- ── Site location ──────────────────────────────────────────── --}}
    <section aria-label="Station location" class="border-b border-zinc-900/10 dark:border-white/10">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-5 pb-4 sm:px-8">
            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                Site · Plzeň-Slovany, CZ
            </p>
            <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                approximate location · {{ number_format($this->approximateLocation['radius']) }} m radius
            </p>
        </div>

        <div
            wire:ignore
            data-station-map
            data-lat="{{ $this->approximateLocation['lat'] }}"
            data-lng="{{ $this->approximateLocation['lng'] }}"
            data-radius="{{ $this->approximateLocation['radius'] }}"
            class="h-64 w-full sm:h-72"
            role="img"
            aria-label="Map showing the approximate area the station reports from"
        ></div>
    </section>

    {{-- ── Footer ─────────────────────────────────────────────────── --}}
    <footer class="flex flex-wrap items-center justify-between gap-2 px-4 py-6 font-mono text-[11px] tracking-widest text-zinc-400 uppercase sm:px-8 dark:text-zinc-500">
        <span>{{ number_format(count($this->readings), 0, ',', ' ') }} records</span>
        <span class="hidden sm:inline">ESP32 → HTTP POST · unix time + t/h/p</span>
        <span>
            &copy; {{ $this->currentYear }} Vladislav Rajtmajer ·
            <a
                href="https://github.com/rajtik76"
                target="_blank"
                rel="noopener noreferrer"
                class="rounded-sm underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:decoration-zinc-600 dark:hover:text-zinc-300 dark:focus-visible:outline-zinc-100"
            >GitHub</a>
        </span>
    </footer>
</div>
