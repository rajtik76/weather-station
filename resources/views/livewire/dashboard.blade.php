{{-- Polling, not broadcasting: one packet per ten minutes does not need a
     websocket. A zoomed window re-queries fixed epochs, so its payload comes
     back identical and the charts are not redrawn under the reader. --}}
<div
    wire:poll.60s
    class="min-h-screen bg-zinc-50 font-sans text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100"
>

    {{-- ── Top bar ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between gap-4 border-b border-zinc-900/10 px-4 py-3 font-mono text-[11px] font-medium tracking-[0.25em] text-zinc-500 uppercase sm:px-8 dark:border-white/10 dark:text-zinc-400">
        <span class="hidden sm:inline">Personal weather station</span>
        <span class="flex items-center gap-2 normal-case tracking-normal">
            <span
                @class([
                    'size-1.5 shrink-0 rounded-full',
                    'bg-emerald-500 animate-breathe motion-reduce:animate-none' => ! $this->isSilent,
                    'bg-amber-500' => $this->isSilent,
                ])
                aria-hidden="true"
            ></span>
            {{-- Colour alone carries the state; name it for screen readers. --}}
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
        {{-- One breakpoint for layout and alignment: with `flex-wrap` the
             row broke at ~1115px while `lg:` right-aligned at 1024px, and
             between the two the readouts hung off the right of their block. --}}
        <div class="flex flex-col gap-x-16 gap-y-10 min-[1120px]:flex-row min-[1120px]:items-start min-[1120px]:justify-between">
            <div>
                <flux:heading level="1" class="font-display text-[clamp(3.5rem,12.5vw,11.5rem)]! leading-[0.78] font-extrabold! tracking-[-0.03em] uppercase">
                    Station<br>Log
                </flux:heading>
                {{-- Instrument Serif runs small beside the sans; one step larger. --}}
                <flux:text class="font-serif mt-6 max-w-2xl text-lg leading-snug italic sm:text-2xl">
                    An SHT41 outside and a BMP280 indoors are read every thirty seconds by an ESP32, which reports each ten minutes as a mean with its extremes.
                    A microphone beside the SHT41 adds the noise: A-weighted levels and a third-octave spectrum per window.
                    Pressure is measured at 345 m and shown reduced to mean sea level.
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

    {{-- ── Sensor ─────────────────────────────────────────────────── --}}
    {{-- Everything below is one sensor's record. The picker appears with a
         second sensor and stands alone on the right, so it shifts nothing. --}}
    <section
        aria-label="Sensor"
        class="mt-10 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-zinc-900/10 px-4 py-4 sm:px-8 dark:border-white/10"
    >
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                Sensor
            </p>
            @if ($this->selectedSensor)
                <p class="font-mono text-xs text-zinc-800 dark:text-zinc-200" data-sensor-name>
                    {{ $this->selectedSensor->name }}
                </p>
                @if ($this->selectedSensor->description)
                    <p class="font-serif text-base leading-snug text-zinc-600 italic dark:text-zinc-400" data-sensor-description>
                        {{ $this->selectedSensor->description }}
                    </p>
                @endif
            @else
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    none registered yet
                </p>
            @endif
        </div>

        @if ($this->hasSensorChoice)
            <flux:select
                wire:model.live="sensor"
                size="sm"
                class="w-auto! min-w-48"
                aria-label="Choose a sensor"
            >
                @foreach ($this->sensors as $option)
                    <flux:select.option :value="$option->slug">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </section>

    {{-- ── Range switcher ─────────────────────────────────────────── --}}
    <section
        aria-label="Chart range"
        class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-y border-zinc-900/10 px-4 py-4 sm:px-8 dark:border-white/10"
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
            {{-- Always rendered, only disabled, so the first zoom does not shift the row mid-click. --}}
            <flux:button
                wire:click="resetZoom"
                :disabled="! $this->isZoomed"
                variant="subtle"
                size="sm"
            >Reset zoom</flux:button>

        </div>
    </section>

    {{-- ── Navigator ──────────────────────────────────────────────── --}}
    {{-- Above the strips: below them a drag moved a chart that was off screen. --}}
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

    {{-- Pressure has its own strip: a 40 hPa spread is a flat line beside
         the others. The dew point is derived, so it starts off. --}}
    @php($strips = [
        [
            'key' => 'th',
            'label' => 'Temperature & humidity',
            'height' => 'h-72 sm:h-80',
            'channels' => [
                ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'accent' => 'bg-amber-600 dark:bg-amber-500', 'toggle' => true],
                ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'accent' => 'bg-cyan-600 dark:bg-cyan-400', 'toggle' => true],
                ['key' => 'd', 'label' => 'Dew point', 'unit' => '°C', 'accent' => 'bg-pink-600 dark:bg-pink-400', 'toggle' => true],
            ],
        ],
        [
            'key' => 'p',
            'label' => 'Pressure, MSL',
            'height' => 'h-48 sm:h-56',
            'channels' => [
                ['key' => 'p', 'label' => 'Pressure, MSL', 'unit' => 'hPa', 'accent' => 'bg-violet-600 dark:bg-violet-500'],
            ],
        ],
    ])

    {{-- station-charts.js watches these attributes; Livewire never touches the canvases. --}}
    <div
        data-chart-rows="{{ json_encode($this->readings) }}"
        data-navigator-rows="{{ json_encode($this->overview) }}"
        data-chart-events="{{ json_encode($this->stationEvents) }}"
        data-hidden-channels="{{ json_encode($this->hiddenChannels) }}"
        data-noise-rows="{{ json_encode($this->noise) }}"
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
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 pt-5 pb-2 sm:px-8">
                @foreach ($strip['channels'] as $channel)
                    @if (isset($channel['toggle']))
                        {{-- The label is the switch; the last one on is disabled instead. --}}
                        @php($shown = $this->channels[$channel['key']] ?? false)
                        @php($last = $this->isLastChannel($channel['key']))
                        <button
                            type="button"
                            wire:click="toggleChannel('{{ $channel['key'] }}')"
                            aria-pressed="{{ $shown ? 'true' : 'false' }}"
                            @disabled($last)
                            @class([
                                'flex items-center gap-2 rounded-sm font-mono text-[11px] font-medium tracking-[0.2em] uppercase focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:focus-visible:outline-zinc-100',
                                'cursor-pointer' => ! $last,
                                'cursor-default' => $last,
                                'text-zinc-500 dark:text-zinc-400' => $shown,
                                'hover:text-zinc-800 dark:hover:text-zinc-200' => $shown && ! $last,
                                'text-zinc-300 hover:text-zinc-500 dark:text-zinc-600 dark:hover:text-zinc-400' => ! $shown,
                            ])
                        >
                            <span
                                @class([
                                    'size-1.5 rounded-full',
                                    $channel['accent'] => $shown,
                                    'bg-zinc-300 dark:bg-zinc-600' => ! $shown,
                                ])
                                aria-hidden="true"
                            ></span>
                            {{ $channel['label'] }} ({{ $channel['unit'] }})
                        </button>
                    @else
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                            <span class="{{ $channel['accent'] }} size-1.5 rounded-full" aria-hidden="true"></span>
                            {{ $channel['label'] }} ({{ $channel['unit'] }})
                        </p>
                    @endif
                @endforeach
            </div>

            {{-- ECharts owns everything below; a morph would tear out the canvas. --}}
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

    {{-- ── Noise ──────────────────────────────────────────────────── --}}
    {{-- Protocol 3 only: a window of older rows has no noise, and no strips. --}}
    @if ($this->noise !== [])
        @php($noiseStrips = [
            [
                'key' => 'noise',
                'label' => 'Noise',
                'height' => 'h-48 sm:h-56',
                'legend' => [
                    ['label' => 'LAeq', 'accent' => 'bg-emerald-600 dark:bg-emerald-400'],
                    ['label' => 'LA90 to LA10', 'accent' => 'bg-emerald-600/25 dark:bg-emerald-400/25'],
                    ['label' => 'LAmax', 'accent' => 'bg-emerald-600/60 dark:bg-emerald-400/60'],
                ],
                'unit' => 'dB(A)',
            ],
            [
                'key' => 'spectrum',
                'label' => 'Noise spectrum',
                'height' => 'h-72 sm:h-80',
                'legend' => [],
                'unit' => 'dB, third octaves 25 Hz to 8 kHz',
            ],
        ])

        @foreach ($noiseStrips as $strip)
            <section
                aria-label="{{ $strip['label'] }} history"
                class="border-b border-zinc-900/10 dark:border-white/10"
            >
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 pt-5 pb-2 sm:px-8">
                    <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                        {{ $strip['label'] }} ({{ $strip['unit'] }})
                    </p>
                    @foreach ($strip['legend'] as $entry)
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                            <span class="{{ $entry['accent'] }} size-1.5 rounded-full" aria-hidden="true"></span>
                            {{ $entry['label'] }}
                        </p>
                    @endforeach
                    @if ($strip['key'] === 'spectrum')
                        {{-- The scale's ends are the window's own quietest and loudest band; station-charts.js fills them in. --}}
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                            <span data-spectrum-low wire:ignore></span>
                            <span data-spectrum-scale wire:ignore class="h-1.5 w-24 rounded-full" aria-hidden="true"></span>
                            <span data-spectrum-high wire:ignore></span>
                        </p>
                    @endif
                </div>

                {{-- ECharts owns everything below; a morph would tear out the canvas. --}}
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
    @endif

    {{-- ── Payload tail ───────────────────────────────────────────── --}}
    @if ($this->recentTransmissions !== [])
        <section aria-label="Last transmissions" class="border-b border-zinc-900/10 dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-4 pb-2 sm:px-8">
                <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                    Last {{ count($this->recentTransmissions) }} measurements · when they arrived
                </p>
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    POST /api/v1/measurement · 0,01 °C · 0,01 % · Pa · UTC unix · samples
                </p>
            </div>

            @foreach ($this->recentTransmissions as $packet)
                <div
                    @class([
                        'grid gap-x-8 gap-y-1 border-t border-zinc-900/10 px-4 py-2 sm:px-8 xl:grid-cols-[minmax(0,1fr)_auto] dark:border-white/10',
                        'border-t-0 bg-zinc-900/5 dark:bg-white/5' => $loop->first,
                    ])
                >
                    {{-- The blob as stored, one field per line: a V2 packet on
                         one line outruns a desktop. Coloured by the key's first word.
                         V3's "noise" object stays on its line as JSON. --}}
                    <p class="font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                        <span class="block text-zinc-400 dark:text-zinc-600">{</span>
                        <span class="block pl-4">"timestamp": <span class="text-zinc-700 dark:text-zinc-300">{{ $packet['timestamp'] }}</span><span class="text-zinc-400 dark:text-zinc-600">,</span></span>
                        @foreach ($packet['packet'] as $field => $value)
                            @php($accent = match (strtok($field, '_')) {
                                'temperature' => 'text-amber-600',
                                'humidity' => 'text-cyan-600',
                                'pressure' => 'text-violet-600 dark:text-violet-500',
                                default => 'text-zinc-700 dark:text-zinc-300',
                            })
                            <span class="block pl-4">"{{ $field }}": <span class="{{ $accent }}{{ is_array($value) ? ' break-all' : '' }}">{{ is_array($value) ? json_encode($value) : $value }}</span>@unless ($loop->last)<span class="text-zinc-400 dark:text-zinc-600">,</span>@endunless</span>
                        @endforeach
                        <span class="block text-zinc-400 dark:text-zinc-600">}</span>
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

    {{-- ── Station report ─────────────────────────────────────────── --}}
    @if ($this->stationReport !== null)
        @php($report = $this->stationReport)
        <section aria-label="Station report" class="border-b border-zinc-900/10 dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-4 pb-2 sm:px-8">
                <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                    Station · as reported with the last upload
                </p>
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $report['at'] }} · {{ $report['ago'] }}
                </p>
            </div>

            {{-- Board only from firmware 2.3, clock rows only once the board has measured a drift. --}}
            <dl class="grid grid-cols-2 gap-x-8 gap-y-3 px-4 pb-4 font-mono text-xs tabular-nums sm:grid-cols-3 sm:px-8 lg:grid-cols-6">
                @foreach ([
                    'firmware' => $report['firmware'],
                    ...($report['board'] === null ? [] : ['board' => $report['board']]),
                    'uptime' => $report['uptime'],
                    'last reset' => $report['resetReason'],
                    'network' => $report['network'],
                    'rssi' => $report['rssi'].' dBm',
                    'heap free' => number_format($report['heapFree'] / 1024, 0, ',', ' ').' kB',
                    'heap lowest' => number_format($report['heapMin'] / 1024, 0, ',', ' ').' kB',
                    'buffered' => $report['buffered'].' '.($report['buffered'] === 1 ? 'window' : 'windows'),
                    'failed uploads' => $report['uploadFailures'].' in a row',
                    'network switches' => $report['switches'],
                    ...($report['clockDrift'] === null ? [] : [
                        'clock drift' => $report['clockDrift'],
                        'clock drift worst' => $report['clockDriftWorst'],
                        'clock synced' => $report['clockSynced'],
                    ]),
                ] as $label => $value)
                    <div>
                        <dt class="text-[11px] tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="text-zinc-800 dark:text-zinc-200">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
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
        <span>{{ number_format($this->recordCount, 0, ',', ' ') }} records</span>
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
