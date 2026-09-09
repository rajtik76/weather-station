{{-- The station uploads every ten minutes, so a minute of latency is nothing
     to a reader. Polling instead of broadcasting keeps a websocket server out
     of the stack for one packet per ten minutes.

     A zoomed window is a pair of fixed epochs, so a poll re-queries exactly
     the readings already on screen and the payload attributes below come back
     byte for byte identical - the charts are never redrawn under a reader who
     is looking at the past. Only the navigator, which always spans the whole
     record, takes the new readings. --}}
<div
    wire:poll.60s
    class="min-h-screen bg-zinc-50 font-sans text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100"
>

    {{-- ── Top bar ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between gap-4 border-b border-zinc-900/10 px-4 py-3 font-mono text-[11px] font-medium tracking-[0.25em] text-zinc-500 uppercase sm:px-8 dark:border-white/10 dark:text-zinc-400">
        <span>ESP32 + BME280</span>
        <span class="flex items-center gap-2 normal-case tracking-normal">
            <span
                @class([
                    'size-1.5 shrink-0 rounded-full',
                    // Breathing only while the link holds; a silent station sits still.
                    'bg-emerald-500 animate-breathe motion-reduce:animate-none' => ! $this->isSilent,
                    'bg-amber-500' => $this->isSilent,
                ])
                aria-hidden="true"
            ></span>
            {{-- Colour alone carries the link state, so name it for screen readers. --}}
            <span class="sr-only">{{ $this->isSilent ? 'Station silent' : 'Station live' }}</span>
            @if ($this->lastMeasurement)
                <span class="tracking-[0.25em] uppercase">Last measurement</span>
                <span class="text-zinc-800 tabular-nums dark:text-zinc-200">{{ $this->measuredAt }}</span>
                <span class="hidden sm:inline">({{ $this->measuredAgo }})</span>
            @else
                <span class="tracking-[0.25em] uppercase">No measurement yet</span>
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
    <header class="border-zinc-900/10 px-4 pt-12 pb-10 sm:px-8 dark:border-white/10">
        {{-- One threshold rules the layout and the alignment together. With
             `flex-wrap` the two disagreed: the row broke where the content
             stopped fitting, around 1115px, while `lg:` turned the readouts
             right-aligned at 1024px - so between the two they sat under the
             hero and still hung off the right of their own block. Wrapping is
             gone, so the readouts either stand beside the text or start under
             it flush left, with nothing in between. --}}
        <div class="flex flex-col gap-x-16 gap-y-10 min-[1120px]:flex-row min-[1120px]:items-start min-[1120px]:justify-between">
            <div>
                <flux:heading level="1" class="font-display text-[clamp(3.5rem,12.5vw,11.5rem)]! leading-[0.78] font-extrabold! tracking-[-0.03em] uppercase">
                    Station<br>Log
                </flux:heading>
                {{-- Instrument Serif runs small and open beside the sans, so the
                     standfirst is set a step larger to hold the same weight on
                     the page. --}}
                <flux:text class="font-serif mt-6 max-w-2xl text-lg leading-snug italic sm:text-2xl">
                    A BME280 on an ESP32 reads temperature, humidity and pressure every ten minutes, around the clock. Every point is a raw
                    record, never averaged. Pressure is measured at 345 m and shown reduced to mean sea level.
                </flux:text>
            </div>

            @if ($this->hasReadings)
            <div class="flex flex-col items-start gap-8 min-[1120px]:items-end min-[1120px]:text-right" aria-label="Current conditions">
                @foreach ([
                    ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 2, 'accent' => 'text-amber-600'],
                    ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 2, 'accent' => 'text-cyan-600'],
                    ['key' => 'p', 'label' => 'Pressure, MSL', 'unit' => 'hPa', 'dec' => 1, 'accent' => 'text-violet-600 dark:text-violet-500'],
                ] as $readout)
                    @php($m = $this->metrics[$readout['key']])
                    <div>
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase min-[1120px]:justify-end dark:text-zinc-400">
                            {{ $readout['label'] }}
                            {{-- The trend carries the channel's own colour, so the
                                 figure reads as belonging to the unit beside it. --}}
                            <span class="{{ $readout['accent'] }} flex items-center gap-2">
                                <flux:icon
                                    :icon="$m['delta'] >= 0.05 ? 'arrow-trending-up' : ($m['delta'] <= -0.05 ? 'arrow-trending-down' : 'minus')"
                                    variant="micro"
                                />
                                {{ ($m['delta'] >= 0 ? '+' : '−') . number_format(abs($m['delta']), $readout['dec'], ',', ' ') }}/h
                            </span>
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
    {{-- The chart controls open a new block of the page, so they stand off the
         hero above instead of stacking flush against it. --}}
    <section
        aria-label="Chart range"
        class="mt-10 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-y border-zinc-900/10 px-4 py-4 sm:px-8 dark:border-white/10"
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
    {{-- Above the strips, with the range switcher: it sets the window rather
         than reporting one, and the two do the same job. Below the strips it
         sat some 700 px under the first grid, so a drag moved a chart that was
         off the screen. --}}
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

    {{-- Temperature and humidity share a strip: they move against each other,
         and two value axes stay readable where three did not. Pressure keeps
         its own - a 40 hPa spread would draw as a flat line beside them. --}}
    @php($strips = [
        [
            'key' => 'th',
            'label' => 'Temperature & humidity',
            'height' => 'h-72 sm:h-80',
            'channels' => [
                ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 2, 'accent' => 'bg-amber-600 dark:bg-amber-500'],
                ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 2, 'accent' => 'bg-cyan-600 dark:bg-cyan-400'],
            ],
        ],
        [
            'key' => 'p',
            'label' => 'Pressure, MSL',
            'height' => 'h-48 sm:h-56',
            'channels' => [
                ['key' => 'p', 'label' => 'Pressure, MSL', 'unit' => 'hPa', 'dec' => 2, 'accent' => 'bg-violet-600 dark:bg-violet-500'],
            ],
        ],
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

    @foreach ($strips as $strip)
        <section
            aria-label="{{ $strip['label'] }} history"
            class="border-b border-zinc-900/10 dark:border-white/10"
        >
            {{-- One header row per channel, so a shared strip still reports each
                 series against its own unit. The dot carries the line's colour,
                 which is what tells the two value axes apart. --}}
            <div class="px-4 pt-5 pb-2 sm:px-8">
                @foreach ($strip['channels'] as $channel)
                    @php($m = $this->metrics[$channel['key']] ?? null)
                    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 not-first:mt-1">
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                            <span class="{{ $channel['accent'] }} size-1.5 rounded-full" aria-hidden="true"></span>
                            {{ $channel['label'] }} · {{ $channel['unit'] }}
                        </p>
                        <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($m)
                                window · min {{ number_format($m['min'], $channel['dec'], ',', ' ') }}
                                · max {{ number_format($m['max'], $channel['dec'], ',', ' ') }}
                                · avg {{ number_format($m['avg'], $channel['dec'], ',', ' ') }}
                            @else
                                no readings in this window
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- `wire:ignore` because ECharts owns everything below this point;
                 a morph would tear the canvas out from under it. --}}
            <div
                wire:ignore
                data-strip="{{ $strip['key'] }}"
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

    {{-- ── Payload tail ───────────────────────────────────────────── --}}
    @if ($this->recentTransmissions !== [])
        <section aria-label="Last transmissions" class="border-b border-zinc-900/10 dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-4 pb-2 sm:px-8">
                <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                    Last {{ count($this->recentTransmissions) }} measurements · as received
                </p>
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    POST /api/v1/measurement · 0,01 °C · 0,01 % · Pa · UTC unix
                </p>
            </div>

            @foreach ($this->recentTransmissions as $packet)
                <div
                    @class([
                        'grid gap-x-8 gap-y-1 border-t border-zinc-900/10 px-4 py-2 sm:px-8 xl:grid-cols-[minmax(0,1fr)_auto] dark:border-white/10',
                        // The newest packet is the one the hero readouts are showing.
                        'border-t-0 bg-zinc-900/5 dark:bg-white/5' => $loop->first,
                    ])
                >
                    {{-- The entry exactly as it arrived in `measurements`. It
                         outruns a phone, so there it breaks into the pretty
                         printed form, one field per line - a scrollbar would
                         hide half the packet behind a gesture. From `md` up
                         the same markup collapses back onto one line, which is
                         the width where all four fields fit without one. --}}
                    <p class="font-mono text-xs text-zinc-500 tabular-nums md:overflow-x-auto md:whitespace-nowrap dark:text-zinc-400">
                        <span class="block text-zinc-400 md:inline dark:text-zinc-600">{</span>
                        <span class="block pl-4 md:inline md:pl-0">"timestamp": <span class="text-zinc-700 dark:text-zinc-300">{{ $packet['timestamp'] }}</span><span class="text-zinc-400 dark:text-zinc-600">,</span></span>
                        <span class="block pl-4 md:inline md:pl-0">"temperature": <span class="text-amber-600">{{ $packet['temperature'] }}</span><span class="text-zinc-400 dark:text-zinc-600">,</span></span>
                        <span class="block pl-4 md:inline md:pl-0">"humidity": <span class="text-cyan-600">{{ $packet['humidity'] }}</span><span class="text-zinc-400 dark:text-zinc-600">,</span></span>
                        <span class="block pl-4 md:inline md:pl-0">"pressure": <span class="text-violet-600 dark:text-violet-500">{{ $packet['pressure'] }}</span></span>
                        <span class="block text-zinc-400 md:inline dark:text-zinc-600">}</span>
                    </p>

                    <p class="flex flex-wrap gap-x-4 font-mono text-xs text-zinc-500 tabular-nums xl:justify-end dark:text-zinc-400">
                        <span>{{ $packet['at'] }}</span>
                        <span><span class="text-zinc-800 dark:text-zinc-200">{{ number_format($packet['t'], 2, ',', ' ') }}</span> °C</span>
                        <span><span class="text-zinc-800 dark:text-zinc-200">{{ number_format($packet['h'], 2, ',', ' ') }}</span> %</span>
                        <span><span class="text-zinc-800 dark:text-zinc-200">{{ number_format($packet['p'], 1, ',', ' ') }}</span> hPa MSL</span>
                        <span class="hidden md:inline">{{ $packet['ago'] }}</span>
                    </p>
                </div>
            @endforeach
        </section>
    @endif

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
