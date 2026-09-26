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
                    A model trained on ČHMÚ station records forecasts the next six hours from these readings alone.
                </flux:text>
            </div>

            @if ($this->hasReadings)
            <div class="flex flex-col items-start gap-8 min-[1120px]:items-end min-[1120px]:text-right" aria-label="Current conditions">
                @foreach ([
                    ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 2, 'accent' => 'text-amber-600'],
                    ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 2, 'accent' => 'text-cyan-600'],
                    ['key' => 'p', 'label' => 'Pressure, MSL', 'unit' => 'hPa', 'dec' => 1, 'accent' => 'text-violet-600 dark:text-violet-500'],
                    ['key' => 'n', 'label' => 'Noise, LAeq', 'unit' => 'dB(A)', 'dec' => 1, 'accent' => 'text-emerald-600 dark:text-emerald-400'],
                ] as $readout)
                    {{-- Noise only when the last day holds some. --}}
                    @continue(! isset($this->metrics[$readout['key']]))
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

    {{-- ── Forecast ───────────────────────────────────────────────── --}}
    {{-- Only while it starts from the current record; Dashboard::forecast(). --}}
    @if ($this->forecast !== null)
        @php($forecast = $this->forecast)
        {{-- Folds like the strips: Alpine state, so a poll keeps it and a reload opens it again. --}}
        <section aria-label="Forecast" class="border-t border-zinc-900/10 dark:border-white/10" x-data="{ collapsed: false }">
            <div
                class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 pt-4 pb-2 sm:px-8"
                x-bind:class="{ 'pb-2': ! collapsed, 'pb-4': collapsed }"
            >
                <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                    Forecast · next 6 hours
                </p>
                <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                    made {{ $forecast['at'] }} · {{ $forecast['ago'] }} ·
                    {{ $forecast['corrected'] ? 'fitted to this station' : 'not yet fitted to this station' }}
                </p>
                <x-fold-button controls="forecast" label="Forecast" />
            </div>

            <div id="forecast" x-bind:class="{ hidden: collapsed }">
                <ol class="grid grid-cols-2 gap-x-8 gap-y-6 px-4 pt-2 pb-4 sm:grid-cols-3 sm:px-8 lg:grid-cols-6">
                    @foreach ($forecast['horizons'] as $hour)
                        <li>
                            <p class="font-mono text-[11px] tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                                +{{ $hour['hours'] }} h · <span class="tabular-nums">{{ $hour['clock'] }}</span>
                            </p>
                            <div class="mt-2 flex items-center gap-3">
                                <flux:icon
                                    :icon="$hour['sky']['icon']"
                                    @class([
                                        'size-9',
                                        'text-sky-600 dark:text-sky-400' => $hour['sky']['tone'] === 'rain',
                                        'text-amber-500' => $hour['sky']['tone'] === 'day',
                                        'text-indigo-400 dark:text-indigo-300' => $hour['sky']['tone'] === 'night',
                                    ])
                                    title="{{ ucfirst($hour['sky']['label']) }}"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{ ucfirst($hour['sky']['label']) }}.</span>
                                <p class="font-display text-4xl leading-none font-bold">
                                    {{ number_format($hour['t'], 1, ',', ' ') }}<span class="ml-1 align-baseline text-lg font-bold text-amber-600">°C</span>
                                </p>
                                <flux:icon
                                    :icon="match ($hour['trend']) { 'rising' => 'arrow-trending-up', 'falling' => 'arrow-trending-down', default => 'minus' }"
                                    variant="mini"
                                    class="text-amber-600"
                                    title="{{ ucfirst($hour['trend']) }}"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{ ucfirst($hour['trend']) }}.</span>
                            </div>
                            <p class="mt-2 flex items-center gap-1.5 font-mono text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                                <flux:icon.thermometer variant="micro" class="text-amber-600" aria-hidden="true" />
                                {{ number_format($hour['tLow'], 1, ',', ' ') }} to {{ number_format($hour['tHigh'], 1, ',', ' ') }}
                            </p>
                            <p class="mt-1 flex items-center gap-1.5 font-mono text-xs tabular-nums">
                                <flux:icon.umbrella variant="micro" class="text-sky-600 dark:text-sky-400" aria-hidden="true" />
                                <span class="text-zinc-500 dark:text-zinc-400">rain</span>
                                <span class="text-zinc-800 dark:text-zinc-200">{{ $hour['rain'] }} %</span>
                            </p>
                        </li>
                    @endforeach
                </ol>

                {{-- CC BY 4.0 asks for the attribution. --}}
                <p class="px-4 pb-4 font-mono text-[11px] text-zinc-500 sm:px-8 dark:text-zinc-400">
                    Range: eight readings in ten land inside it. Rain: the chance of at least 0.1 mm by then.
                    Trained on ČHMÚ station records (CC BY 4.0), run from this station's readings alone.
                </p>

                {{-- Inside the forecast's fold, so folding the forecast takes it along.
                     Starts folded: rendered hidden, so nothing flashes before Alpine runs. --}}
                @if ($this->accuracyScore !== null)
                    @php($score = $this->accuracyScore)
                    @php($scoredHours = array_column($this->forecastAccuracy, 'hours'))
                    @php($figures = array_filter([
                        ['label' => 'With correction', 'figures' => $score['corrected']],
                        ['label' => 'Base model', 'figures' => $score['base']],
                    ], fn (array $row): bool => $row['figures'] !== null))
                    <div class="border-t border-zinc-900/5 dark:border-white/5" x-data="{ collapsed: true }">
                        <div class="flex items-center gap-x-6 px-4 py-3 sm:px-8">
                            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                                Accuracy · last {{ $this::ACCURACY_DAYS }} days
                            </p>
                            <x-fold-button controls="forecast-accuracy" label="Forecast accuracy" :collapsed="true" />
                        </div>

                        <div id="forecast-accuracy" class="hidden" x-bind:class="{ hidden: collapsed }">
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 sm:px-8">
                                <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                                    <span class="size-1.5 rounded-full bg-amber-600 dark:bg-amber-500" aria-hidden="true"></span>
                                    With correction
                                </p>
                                <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                                    <span class="w-3 border-t border-dashed border-zinc-400" aria-hidden="true"></span>
                                    Base model
                                </p>
                                {{-- Every hour the forecast reaches, so the control never grows under the pointer; one not scored yet is disabled. --}}
                                <flux:radio.group wire:model.live="accuracyHorizon" variant="segmented" size="sm" aria-label="Hours ahead" class="ml-auto">
                                    @foreach ($forecast['horizons'] as $horizon)
                                        <flux:radio :value="$horizon['hours']" label="+{{ $horizon['hours'] }} h" :disabled="! in_array($horizon['hours'], $scoredHours, true)" />
                                    @endforeach
                                </flux:radio.group>
                            </div>

                            {{-- forecast-accuracy.js watches the attribute; Livewire never touches the canvas. --}}
                            <div
                                class="px-4 pt-2 sm:px-8"
                                data-accuracy-chart="days"
                                data-accuracy-rows="{{ json_encode($score['days']) }}"
                                aria-label="Temperature {{ $score['hours'] }} h ahead by day: how much smaller the miss was than the naive guess's, with the station correction and without"
                                role="img"
                            >
                                <div wire:ignore data-accuracy-canvas class="h-40 w-full"></div>
                            </div>

                            <div class="overflow-x-auto px-4 sm:px-8">
                                <table class="w-full font-mono text-xs tabular-nums">
                                    <thead>
                                        <tr class="text-left text-[11px] tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                                            <th class="py-2 pr-6 font-medium">+{{ $score['hours'] }} h</th>
                                            <th class="py-2 pr-6 font-medium">Better by</th>
                                            <th class="py-2 pr-6 font-medium">Off by · naive</th>
                                            <th class="py-2 pr-6 font-medium">In range</th>
                                            <th class="py-2 pr-6 font-medium">Range width</th>
                                            <th class="py-2 font-medium">Forecasts</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-zinc-800 dark:text-zinc-200">
                                        @foreach ($figures as $row)
                                            @php($shown = $row['figures'])
                                            <tr class="border-t border-zinc-900/5 dark:border-white/5">
                                                <td class="py-1.5 pr-6 whitespace-nowrap">{{ $row['label'] }}</td>
                                                <td class="py-1.5 pr-6">{{ $shown['skill'] === null ? 'n/a' : ($shown['skill'] > 0 ? '+' : '').number_format($shown['skill'], 0).' %' }}</td>
                                                <td class="py-1.5 pr-6 whitespace-nowrap">
                                                    @if ($shown['error'] === null)
                                                        n/a
                                                    @else
                                                        {{ number_format($shown['error'], 1, ',', ' ') }} · {{ number_format($shown['naive'], 1, ',', ' ') }} °C
                                                    @endif
                                                </td>
                                                <td class="py-1.5 pr-6">{{ number_format($shown['inRange'], 0) }} %</td>
                                                <td class="py-1.5 pr-6">{{ number_format($shown['width'], 1, ',', ' ') }} °C</td>
                                                <td class="py-1.5">{{ $shown['count'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <p class="px-4 pt-2 font-mono text-xs text-zinc-800 sm:px-8 dark:text-zinc-200">
                                <span class="text-[11px] tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">Rain chance · rained / dry</span>
                                <span class="ml-2 tabular-nums">
                                    @if ($score['rain']['count'] === 0)
                                        <span class="text-zinc-500 dark:text-zinc-400">not listened</span>
                                    @else
                                        {{ $score['rain']['chanceWhenRain'] === null ? 'no rain' : number_format($score['rain']['chanceWhenRain'], 0).' %' }}
                                        /
                                        {{ $score['rain']['chanceWhenDry'] === null ? 'no dry spell' : number_format($score['rain']['chanceWhenDry'], 0).' %' }}
                                    @endif
                                </span>
                            </p>

                            {{-- The hour-of-day detail starts closed: rendered hidden, so nothing flashes before Alpine runs. --}}
                            <div x-data="{ open: false }" class="px-4 pt-3 sm:px-8">
                                <flux:button
                                    x-on:click="open = ! open"
                                    variant="subtle"
                                    size="xs"
                                    icon="presentation-chart-line"
                                    aria-expanded="false"
                                    x-bind:aria-expanded="open ? 'true' : 'false'"
                                    aria-controls="forecast-accuracy-hours"
                                    x-bind:class="{ 'text-zinc-800! dark:text-white!': open }"
                                >By hour of the day</flux:button>

                                {{-- Hidden, not removed: the chart keeps its instance and resizes once it has a size. --}}
                                <div id="forecast-accuracy-hours" class="hidden pt-2" x-bind:class="{ hidden: ! open }">
                                    <div
                                        data-accuracy-chart="hours"
                                        data-accuracy-rows="{{ json_encode($score['byHour']) }}"
                                        aria-label="Temperature {{ $score['hours'] }} h ahead, measured minus forecast by hour of the day"
                                        role="img"
                                    >
                                        <div wire:ignore data-accuracy-canvas class="h-24 w-full"></div>
                                    </div>
                                </div>
                            </div>

                            <p class="px-4 pt-2 pb-4 font-mono text-[11px] text-zinc-500 sm:px-8 dark:text-zinc-400">
                                Better by: how much smaller the forecast's miss was than the naive guess's - the temperature at the time of the forecast, kept for the hours ahead.
                                Above zero the forecast beats the guess, below zero it does worse.
                                Base model: the forecast as trained on ČHMÚ records, before the correction this station's own misses teach it;
                                the gap between the two lines is what the correction has learnt.
                                In range: a good range holds eight readings in ten, so 80 % is on target and 100 % means it was drawn too wide.
                                Rain as the microphone heard it: the mean chance given when it rained and when it stayed dry. Drizzle is not heard.
                                By hour of the day: measured minus the middle of the forecast shown, above zero warmer than forecast.
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endif

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
        data-noise-rain="{{ json_encode($this->rainSlots) }}"
        data-window-from="{{ $this->windowMs['from'] }}"
        data-window-to="{{ $this->windowMs['to'] }}"
        data-chart-component="{{ $this->getId() }}"
        hidden
    ></div>

    @foreach ($strips as $strip)
        <x-strip :key="$strip['key']" :label="$strip['label']" :height="$strip['height']">
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
        </x-strip>
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
            <x-strip :key="$strip['key']" :label="$strip['label']" :height="$strip['height']">
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
            </x-strip>
        @endforeach
    @endif

    {{-- ── Payload tail ───────────────────────────────────────────── --}}
    @if ($this->recentTransmissions !== [])
        @php($transmissionCount = count($this->recentTransmissions))
        <section
            aria-label="Last transmissions"
            class="border-b border-zinc-900/10 dark:border-white/10"
            x-data="{ all: false }"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-4 pb-2 sm:px-8">
                {{-- Counts what is on screen: folded, that is the newest one alone. --}}
                <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                    <span x-bind:class="{ hidden: all }">Last measurement · when it arrived</span>
                    @if ($transmissionCount > 1)
                        <span class="hidden" x-bind:class="{ hidden: ! all }">Last {{ $transmissionCount }} measurements · when they arrived</span>
                    @endif
                </p>
                <div class="flex items-center gap-x-4">
                    <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                        POST /api/v1/measurement · 0,01 °C · 0,01 % · Pa · UTC unix · samples
                    </p>
                    {{-- Folded, the newest transmission still shows; only the older ones go. Nothing to unfold with one. --}}
                    <flux:button
                        x-on:click="all = ! all"
                        variant="subtle"
                        size="xs"
                        icon="chevron-down"
                        aria-expanded="false"
                        x-bind:aria-expanded="all ? 'true' : 'false'"
                        aria-controls="transmissions"
                        aria-label="Show all transmissions"
                        x-bind:aria-label="all ? 'Show the last transmission only' : 'Show all transmissions'"
                        x-bind:class="{ '[&_svg]:rotate-180': all }"
                        :disabled="$transmissionCount < 2"
                    />
                </div>
            </div>

            <div id="transmissions">
                @foreach ($this->recentTransmissions as $packet)
                    <div
                        @class([
                            'grid gap-x-8 gap-y-1 border-t border-zinc-900/10 px-4 py-2 sm:px-8 xl:grid-cols-[minmax(0,1fr)_auto] dark:border-white/10',
                            'border-t-0 bg-zinc-900/5 dark:bg-white/5' => $loop->first,
                            'hidden' => ! $loop->first,
                        ])
                        @unless ($loop->first)
                            x-bind:class="{ hidden: ! all }"
                        @endunless
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
            </div>
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
