{{-- Polling, not broadcasting: one packet per ten minutes does not need a
     websocket. A zoomed window re-queries fixed epochs, so its payload comes
     back identical and the charts are not redrawn under the reader. --}}
<div
    wire:poll.60s
    class="sky-page min-h-screen font-sans font-medium text-slate-900 dark:text-slate-100"
>
    @php($tones = [
        't' => 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        'h' => 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
        'p' => 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        'n' => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        'spectrum' => 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
        'good' => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        'rain' => 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    ])

    <div class="mx-auto max-w-[1320px] px-3 pt-4 pb-16 sm:px-6">

        {{-- ── Top bar ────────────────────────────────────────────── --}}
        {{-- Everything below is one sensor's record. The picker appears with a
             second sensor and sits beside the name, so it shifts nothing else. --}}
        <nav aria-label="Station" class="flex flex-wrap items-center gap-3 px-1">
            <span class="text-[17px] font-extrabold tracking-[-0.02em]">{{ config('app.name') }}</span>
            <span class="flex-1"></span>

            <section aria-label="Sensor" class="pill">
                <span class="text-slate-500 dark:text-slate-400">Sensor</span>
                @if ($this->hasSensorChoice)
                    {{-- The pill is the control's shape: the native select inside it loses its own
                         box and keeps only its text and chevron. --}}
                    <flux:select
                        wire:model.live="sensor"
                        size="sm"
                        class="h-7! w-auto! rounded-full! border-0! bg-transparent! py-0! ps-0! pe-6! text-[13.5px]! font-bold! text-slate-900! shadow-none! bg-position-[right_center]! bg-size-[1.25em]! focus-visible:outline-2 focus-visible:outline-offset-4 dark:bg-transparent! dark:text-slate-100!"
                        aria-label="Choose a sensor"
                    >
                        @foreach ($this->sensors as $option)
                            <flux:select.option :value="$option->slug">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @elseif ($this->selectedSensor)
                    <span class="font-bold" data-sensor-name>{{ $this->selectedSensor->name }}</span>
                @else
                    <span class="text-slate-500 dark:text-slate-400">none registered yet</span>
                @endif
            </section>

            <button
                type="button"
                x-data
                x-on:click="$flux.dark = ! $flux.dark"
                class="pill size-[34px] justify-center p-0!"
                aria-label="Toggle dark mode"
            >
                <flux:icon.moon variant="mini" class="size-4 dark:hidden" />
                <flux:icon.sun variant="mini" class="hidden size-4 dark:block" />
            </button>
        </nav>

        {{-- ── Story ──────────────────────────────────────────────── --}}
        {{-- The question first: the sky below answers it for right now, the verdict for the last month. --}}
        <header class="grid items-end gap-x-14 gap-y-5 px-1 pt-7 pb-6 sm:pt-10 sm:pb-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
            <h1 class="max-w-[18ch] text-[clamp(2rem,4.2vw,3.5rem)] leading-[1.04] font-extrabold tracking-[-0.04em] text-balance">
                Can a balcony station forecast its own next six hours better than assuming nothing changes?
            </h1>
            <div>
                <p class="max-w-[58ch] text-[16.5px] leading-normal font-semibold sm:text-lg">
                    A model trained on station records from the Czech Hydrometeorological Institute (ČHMÚ) tries, from these readings alone, and every forecast is scored against what the sensor measured next.
                </p>
                <p class="mt-3 max-w-[58ch] text-[15.5px] leading-relaxed text-slate-500 dark:text-slate-400">
                    An SHT41 outside and a BMP280 indoors are read every thirty seconds by an ESP32, which reports each ten minutes as a mean with its extremes.
                    An INMP441 microphone beside the SHT41 adds the noise: A-weighted levels and a third-octave spectrum per window.
                    Pressure is measured at 345 m and shown reduced to mean sea level.
                </p>
                @if ($this->verdict !== null && $this->verdict['ready'])
                    @php($beats = $this->verdict['skill'] >= 0)
                    <a
                        href="#verdict"
                        @class([
                            'mt-5 inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-[13.5px] font-extrabold focus-visible:outline-2 focus-visible:outline-offset-2',
                            'bg-emerald-500/15 text-emerald-700 hover:bg-emerald-500/25 dark:text-emerald-400' => $beats,
                            'bg-rose-500/15 text-rose-700 hover:bg-rose-500/25 dark:text-rose-400' => ! $beats,
                        ])
                    >
                        <flux:icon.circle-check variant="mini" class="size-4" />
                        So far: a {{ number_format(abs($this->verdict['skill']), 0) }} % {{ $beats ? 'smaller' : 'larger' }} miss than the naive guess
                        <flux:icon.chevron-down variant="micro" />
                    </a>
                @endif
            </div>
        </header>

        <div class="grid grid-cols-12 gap-3 sm:gap-3.5">

            {{-- ── Sky: now and the next six hours ────────────────── --}}
            @php($forecast = $this->forecast)
            @php($temperature = $this->metrics['t'] ?? null)
            <section
                aria-label="Now and the next six hours"
                @class([
                    'sky relative isolate col-span-12 grid gap-x-10 gap-y-6 overflow-hidden rounded-[22px] px-5 pt-6 pb-5 text-white shadow-[0_24px_50px_-30px_rgb(20_50_100/0.6)] [text-shadow:0_1px_8px_rgb(0_0_0/0.25)] sm:rounded-[28px] sm:px-8 sm:pt-7',
                    'lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)]' => $forecast !== null,
                ])
            >
                @if ($this->skyScene !== null)
                    {{-- The sky as it is, generated for this page (public/images/weather-backgrounds/README.md).
                         Over the plain gradient, which shows while it loads. On a phone the crop keeps the
                         sun's side; dimmed under the reading and the forecast alike. --}}
                    <img
                        src="{{ asset('images/weather-backgrounds/'.$this->skyScene.'.webp') }}"
                        srcset="{{ asset('images/weather-backgrounds/'.$this->skyScene.'-768.webp') }} 768w, {{ asset('images/weather-backgrounds/'.$this->skyScene.'.webp') }} 1536w"
                        sizes="(min-width: 1320px) 1320px, 100vw"
                        alt=""
                        class="pointer-events-none absolute inset-0 -z-20 size-full object-cover object-[72%_50%] lg:object-center"
                        data-sky-scene="{{ $this->skyScene }}"
                    >
                    <div class="pointer-events-none absolute inset-0 -z-10 bg-linear-to-b from-slate-950/50 via-slate-950/40 to-slate-950/50 lg:bg-linear-to-r lg:from-slate-950/55 lg:via-slate-950/40 lg:to-slate-950/50" aria-hidden="true"></div>
                @endif

                <div class="relative">
                    {{-- Where, which sensor and when, each on a line of its own, so the stamp never breaks mid-phrase
                         and the description sits under the name it describes. --}}
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[15px] font-bold">
                        <span
                            @class([
                                'size-[7px] shrink-0 rounded-full',
                                'bg-emerald-300 shadow-[0_0_0_4px_rgb(110_231_183/0.25)] animate-breathe motion-reduce:animate-none' => ! $this->isSilent,
                                'bg-amber-300 shadow-[0_0_0_4px_rgb(252_211_77/0.25)]' => $this->isSilent,
                            ])
                            aria-hidden="true"
                        ></span>
                        {{-- Colour alone carries the state; name it for screen readers. --}}
                        <span class="sr-only">{{ $this->isSilent ? 'Station silent' : 'Station live' }}</span>
                        Plzeň-Slovany
                        @if ($this->selectedSensor)
                            <span class="font-semibold text-white/60" aria-hidden="true">·</span>
                            <span class="font-mono text-[13.5px] font-semibold text-white/90" data-sky-sensor-name>{{ $this->selectedSensor->name }}</span>
                        @endif
                    </p>
                    @if ($this->selectedSensor?->description)
                        <p class="mt-1 max-w-md pl-[15px] text-[13px] leading-snug text-white/80" data-sensor-description>
                            {{ $this->selectedSensor->description }}
                        </p>
                    @endif
                    <p class="mt-1 pl-[15px] text-[13px] text-white/80 tabular-nums">
                        @if ($this->lastMeasurement)
                            Last measurement {{ $this->measuredAt }} · {{ $this->measuredAgo }}
                        @else
                            No measurement yet
                        @endif
                    </p>

                    @if ($temperature !== null)
                        <p class="mt-6 text-[clamp(4.5rem,11vw,9.5rem)] leading-[0.85] font-extrabold tracking-[-0.06em] tabular-nums" aria-label="Temperature">
                            {{ number_format($temperature['now'], 2, ',', ' ') }}<span class="relative top-[0.35em] ml-1.5 align-top text-[0.32em] font-bold tracking-[-0.01em] text-white/90">°C</span>
                        </p>

                        {{-- The temperature's own day, tied to the number above it: clear of the comma's
                             descender, and quieter than the live readings below. --}}
                        {{-- Three fixed columns, lined up with the live readings under them, so nothing wraps on its own. --}}
                        <dl class="mt-6 grid max-w-lg grid-cols-3 sm:mt-7" data-sky-temperature-day>
                            @foreach ([
                                [
                                    'label' => 'Last hour',
                                    'icon' => $temperature['delta'] >= 0.05 ? 'arrow-trending-up' : ($temperature['delta'] <= -0.05 ? 'arrow-trending-down' : 'minus'),
                                    'value' => ($temperature['delta'] >= 0 ? '+' : '−') . number_format(abs($temperature['delta']), 2, ',', ' '),
                                    'unit' => '°C',
                                ],
                                ['label' => '24 h low', 'icon' => 'arrow-down', 'value' => number_format($temperature['dayMin'], 2, ',', ' '), 'unit' => '°C'],
                                ['label' => '24 h high', 'icon' => 'arrow-up', 'value' => number_format($temperature['dayMax'], 2, ',', ' '), 'unit' => '°C'],
                            ] as $item)
                                <div class="min-w-0 px-3 first:pl-0 sm:px-4">
                                    <dt class="text-[11px] font-semibold tracking-[0.06em] whitespace-nowrap text-white/70 uppercase">{{ $item['label'] }}</dt>
                                    <dd class="mt-0.5 flex items-center gap-1 text-[15px] font-bold whitespace-nowrap tabular-nums">
                                        <flux:icon :icon="$item['icon']" variant="micro" class="size-3.5 shrink-0 text-white/75" aria-hidden="true" />
                                        {{ $item['value'] }}<span class="text-xs font-semibold text-white/75">{{ $item['unit'] }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        {{-- The rest of the reading now, straight under the temperature; the tiles below carry their day.
                             Noise only while the newest reading has it: a dead microphone leaves the day's last level behind. --}}
                        @php($liveReadouts = array_filter([
                            ['key' => 'h', 'label' => 'Humidity', 'icon' => 'droplet', 'unit' => '%', 'dec' => 2],
                            ['key' => 'p', 'label' => 'Pressure', 'icon' => 'gauge', 'unit' => 'hPa', 'dec' => 1],
                            ['key' => 'n', 'label' => 'Noise', 'icon' => 'audio-waveform', 'unit' => 'dB(A)', 'dec' => 1],
                        ], fn (array $readout): bool => isset($this->metrics[$readout['key']]) && ($readout['key'] !== 'n' || $this->isNoiseCurrent)))
                        <dl
                            @class([
                                'mt-5 grid max-w-lg divide-x divide-white/20 border-y border-white/20 py-3',
                                'grid-cols-3' => count($liveReadouts) === 3,
                                'grid-cols-2' => count($liveReadouts) === 2,
                            ])
                            data-sky-readouts
                        >
                            @foreach ($liveReadouts as $readout)
                                <div class="min-w-0 px-3 first:pl-0 sm:px-4">
                                    <dt class="flex items-center gap-1 text-[11.5px] font-semibold tracking-[0.06em] text-white/75 uppercase">
                                        <flux:icon :icon="$readout['icon']" variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ $readout['label'] }}
                                    </dt>
                                    <dd class="mt-1 text-xl leading-tight font-extrabold tracking-[-0.02em] whitespace-nowrap tabular-nums sm:text-[22px]">
                                        {{ number_format($this->metrics[$readout['key']]['now'], $readout['dec'], ',', ' ') }}<span class="ml-1 text-xs font-semibold tracking-normal text-white/80">{{ $readout['unit'] }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        {{-- After the live readings: rain only when the microphone hears it (dry goes unsaid), then the next hour. --}}
                        @if ($this->rainHeard || $forecast !== null)
                            <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2">
                                @if ($this->rainHeard)
                                    <p class="inline-flex items-center gap-2 rounded-full bg-sky-950/35 px-3 py-1.5 text-[15px] font-extrabold ring-1 ring-white/30" data-rain-now>
                                        <flux:icon.cloud-rain variant="mini" class="size-5" aria-hidden="true" />
                                        Raining now
                                        <span class="text-xs font-semibold text-white/75">heard by the INMP441 microphone</span>
                                    </p>
                                @endif
                                @if ($forecast !== null)
                                    @php($next = $forecast['horizons'][0])
                                    <p class="flex items-center gap-2 text-[15px] font-bold">
                                        <flux:icon :icon="$next['sky']['icon']" variant="mini" class="size-5" aria-hidden="true" />
                                        Next hour: {{ $next['sky']['label'] }}, {{ $next['trend'] }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    @endif

                </div>

                {{-- Only while it starts from the current record; Dashboard::forecast(). --}}
                @if ($forecast !== null)
                    {{-- A hairline between the reading now and the hours ahead: beside it on a wide screen, above it once stacked. --}}
                    <div id="forecast" class="relative flex min-w-0 flex-col border-t border-white/30 pt-6 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-10 dark:border-white/15" aria-label="Forecast">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <h2 class="text-[15px] font-bold">Next six hours</h2>
                            <p class="text-[13px] text-white/80">
                                made {{ $forecast['at'] }} · {{ $forecast['ago'] }} ·
                                {{ $forecast['corrected'] ? 'fitted to this station' : 'not yet fitted to this station' }}
                            </p>
                        </div>

                        @php($curve = $this->forecastCurve)
                        @if ($curve !== null)
                            {{-- Now, then each hour's median over its range. One column per point, as in the list below;
                                 both sit right of the same gutter, which holds the y-axis labels. --}}
                            <div class="relative mt-4 ml-10 h-28 sm:h-36" data-forecast-curve aria-hidden="true">
                                @foreach ($curve['ticks'] as $tick)
                                    <span
                                        class="absolute right-full mr-2 -translate-y-1/2 font-mono text-[11px] whitespace-nowrap text-white/75 tabular-nums"
                                        style="top: {{ $tick['y'] }}%"
                                        data-forecast-tick
                                    >{{ number_format($tick['value'], 1, ',', ' ') }}°</span>
                                @endforeach
                                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible">
                                    @foreach ($curve['ticks'] as $tick)
                                        <line x1="0" x2="100" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" class="stroke-white/25" stroke-width="1" stroke-dasharray="2 3" vector-effect="non-scaling-stroke" />
                                    @endforeach
                                    <path d="{{ $curve['band'] }}" class="fill-white/20" />
                                    <path d="{{ $curve['line'] }}" class="fill-none stroke-white" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                                </svg>
                                @foreach ($curve['points'] as $point)
                                    <span
                                        @class([
                                            'absolute size-[9px] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white',
                                            'bg-white' => $loop->first,
                                            'bg-sky-500 dark:bg-indigo-900' => ! $loop->first,
                                        ])
                                        style="left: {{ $point['x'] }}%; top: {{ $point['y'] }}%"
                                    ></span>
                                @endforeach
                            </div>
                        @endif

                        {{-- As many columns as the curve has points, so each label sits under its own. --}}
                        <ol @class(['mt-2 grid text-center', 'ml-10' => $curve !== null]) style="grid-template-columns: repeat({{ count($forecast['horizons']) + 1 }}, minmax(0, 1fr))">
                            <li class="flex flex-col items-center gap-1 px-0.5">
                                <span class="text-xs font-bold">now</span>
                                <span class="font-mono text-[11px] text-white/75 tabular-nums">{{ $this->lastMeasurement?->clock() }}</span>
                                @if ($temperature !== null)
                                    <span class="mt-7 text-[15px] font-extrabold tabular-nums">{{ number_format($temperature['now'], 1, ',', ' ') }}°</span>
                                @endif
                            </li>
                            @foreach ($forecast['horizons'] as $hour)
                                <li class="flex min-w-0 flex-col items-center gap-1 px-0.5">
                                    <span class="text-xs font-bold">+{{ $hour['hours'] }} h</span>
                                    <span class="font-mono text-[11px] text-white/75 tabular-nums">{{ $hour['clock'] }}</span>
                                    <flux:icon :icon="$hour['sky']['icon']" class="size-6" title="{{ ucfirst($hour['sky']['label']) }}" aria-hidden="true" />
                                    <span class="sr-only">{{ ucfirst($hour['sky']['label']) }}.</span>
                                    <span class="flex items-center gap-0.5 text-[15px] font-extrabold tabular-nums">
                                        {{ number_format($hour['t'], 1, ',', ' ') }}°
                                        <flux:icon
                                            :icon="match ($hour['trend']) { 'rising' => 'arrow-trending-up', 'falling' => 'arrow-trending-down', default => 'minus' }"
                                            variant="micro"
                                            class="text-white/80"
                                            title="{{ ucfirst($hour['trend']) }}"
                                            aria-hidden="true"
                                        />
                                        <span class="sr-only">{{ ucfirst($hour['trend']) }}.</span>
                                    </span>
                                    <span class="hidden font-mono text-[10.5px] leading-tight text-white/80 tabular-nums sm:block">{{ number_format($hour['tLow'], 1, ',', ' ') }} to {{ number_format($hour['tHigh'], 1, ',', ' ') }}</span>
                                    <span class="inline-flex items-center gap-0.5 font-mono text-[11px] text-white/85 tabular-nums">
                                        <flux:icon.umbrella variant="micro" class="size-3" aria-hidden="true" />
                                        <span class="sr-only">rain</span>
                                        {{ $hour['rain'] }} %
                                    </span>
                                </li>
                            @endforeach
                        </ol>

                        {{-- CC BY 4.0 asks for the attribution. --}}
                        <p class="mt-4 text-[12.5px] leading-snug text-white/80">
                            Range: eight readings in ten land inside it. Rain: the chance of at least 0.1 mm by then.
                            Trained on ČHMÚ station records (CC BY 4.0), run from this station's readings alone.
                        </p>
                    </div>
                @endif
            </section>

            {{-- Credit for the sky pictures, under the widget as a photo's would be. Always shown:
                 it belongs to the pictures, not to whether the station is on the air. --}}
            <p class="col-span-12 -mt-1.5 px-2 text-right text-xs text-slate-500 sm:-mt-2 dark:text-slate-400" data-sky-credit>
                Sky image generated with OpenAI
            </p>

            {{-- ── Verdict ────────────────────────────────────────── --}}
            {{-- The answer to the story's question; history, so it stays while a forecast is stale. Dashboard::verdict(). --}}
            @if ($this->verdict !== null)
                @php($verdict = $this->verdict)
                <x-tile
                    id="verdict"
                    title="Is it working?"
                    icon="circle-check"
                    :tone="$tones['good']"
                    hint="Last {{ $this::ACCURACY_DAYS }} days, {{ $verdict['count'] }} {{ Str::plural('forecast', $verdict['count']) }} scored"
                    aria-label="Verdict"
                    class="col-span-12 scroll-mt-4 lg:col-span-5"
                >
                    @if (! $verdict['ready'])
                        <p class="text-[15px] leading-normal font-semibold">
                            Too early to say: the verdict waits for a day of forecasts that have come true.
                        </p>
                    @else
                        @php($beats = $verdict['skill'] >= 0)
                        {{-- The ring fills with the share the miss shrank by; a loss draws in rose from the same start. --}}
                        @php($filled = min(100, abs($verdict['skill'])))
                        <div class="grid items-center gap-x-5 gap-y-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                            <div class="relative size-[132px]">
                                <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true">
                                    <circle cx="60" cy="60" r="54" fill="none" stroke-width="11" @class(['stroke-emerald-500/15' => $beats, 'stroke-rose-500/15' => ! $beats]) />
                                    <circle
                                        cx="60" cy="60" r="54" fill="none" stroke-width="11" stroke-linecap="round"
                                        @class(['stroke-emerald-500' => $beats, 'stroke-rose-500' => ! $beats])
                                        stroke-dasharray="339.29"
                                        stroke-dashoffset="{{ round(339.29 * (1 - $filled / 100), 2) }}"
                                    />
                                </svg>
                                <div class="absolute inset-0 grid place-content-center text-center">
                                    <span @class([
                                        'text-[34px] leading-none font-extrabold tracking-[-0.04em]',
                                        'text-rose-600 dark:text-rose-400' => ! $beats,
                                    ])>{{ number_format(abs($verdict['skill']), 0) }} %</span>
                                    <span class="text-[11.5px] text-slate-500 dark:text-slate-400">{{ $beats ? 'smaller' : 'larger' }} miss</span>
                                </div>
                            </div>

                            <div>
                                <p class="text-[15px] leading-normal font-semibold">
                                    A {{ number_format(abs($verdict['skill']), 0) }} % {{ $beats ? 'smaller' : 'larger' }} miss than assuming it stays as warm as now, {{ $verdict['hours'] }} h ahead.
                                </p>
                                <dl class="mt-3 grid grid-cols-2 gap-2.5">
                                    <div class="rounded-[14px] bg-slate-900/[0.04] px-3 py-2.5 dark:bg-white/[0.04]">
                                        <dt class="sr-only">Off on average</dt>
                                        <dd class="text-[22px] leading-tight font-extrabold tracking-[-0.03em]">{{ number_format($verdict['error'], 1, ',', ' ') }} °C</dd>
                                        <dd class="text-[12.5px] text-slate-500 dark:text-slate-400">off on average, the naive guess {{ number_format($verdict['naive'], 1, ',', ' ') }} °C</dd>
                                    </div>
                                    <div class="rounded-[14px] bg-slate-900/[0.04] px-3 py-2.5 dark:bg-white/[0.04]">
                                        <dt class="sr-only">Inside the forecast range</dt>
                                        <dd class="text-[22px] leading-tight font-extrabold tracking-[-0.03em]">{{ number_format($verdict['inRange'], 0) }} %</dd>
                                        <dd class="text-[12.5px] text-slate-500 dark:text-slate-400">inside the forecast range, target 80 %</dd>
                                    </div>
                                </dl>
                            </div>

                            {{-- Every horizon beside the headline, so six hours is not the only number picked.
                                 Each hour ahead has its own hue, near to far; a loss still reads rose in its figure. --}}
                            @php($horizonTones = [
                                1 => ['bg-emerald-500/15', 'text-emerald-700 dark:text-emerald-300'],
                                2 => ['bg-teal-500/15', 'text-teal-700 dark:text-teal-300'],
                                3 => ['bg-cyan-500/15', 'text-cyan-700 dark:text-cyan-300'],
                                4 => ['bg-sky-500/15', 'text-sky-700 dark:text-sky-300'],
                                5 => ['bg-indigo-500/15', 'text-indigo-700 dark:text-indigo-300'],
                                6 => ['bg-violet-500/15', 'text-violet-700 dark:text-violet-300'],
                            ])
                            <ol class="grid grid-cols-3 gap-1.5 sm:col-span-2 sm:grid-cols-6" aria-label="Smaller miss than the naive guess by hours ahead">
                                @foreach ($verdict['horizons'] as $horizon)
                                    @php($skill = $horizon['skill'])
                                    @php([$horizonBackground, $horizonInk] = $horizonTones[$horizon['hours']] ?? ['bg-slate-900/[0.04] dark:bg-white/[0.04]', 'text-slate-500 dark:text-slate-400'])
                                    <li class="{{ $horizonBackground }} rounded-xl px-1.5 py-2 text-center">
                                        <span class="{{ $horizonInk }} block font-mono text-[11.5px]">+{{ $horizon['hours'] }} h</span>
                                        <span @class([
                                            'block text-sm font-extrabold tabular-nums',
                                            'text-rose-600 dark:text-rose-400' => $skill !== null && $skill < 0,
                                        ])>{{ $skill === null ? 'n/a' : ($skill > 0 ? '+' : '').number_format($skill, 0).' %' }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif
                </x-tile>
            @endif

            {{-- ── How it works ───────────────────────────────────── --}}
            {{-- A real sequence, so it is numbered. --}}
            <x-tile
                title="How the forecast is made"
                icon="activity"
                :tone="$tones['rain']"
                aria-label="How it works"
                :class="\Illuminate\Support\Arr::toCssClasses(['col-span-12', 'lg:col-span-7' => $this->verdict !== null])"
            >
                <ol class="grid grid-cols-2 gap-2.5 md:grid-cols-4">
                    @foreach ([
                        ['Measure', 'An SHT41 and an INMP441 microphone outside, a BMP280 indoors, read every 30 seconds.'],
                        ['Upload', 'The ESP32 sends each ten minutes as a mean with its extremes.'],
                        ['Forecast', 'A model trained on ČHMÚ records looks six hours ahead, corrected by this station\'s own misses.'],
                        ['Score', 'When the hour comes, the forecast is checked against what the sensor measured.'],
                    ] as [$step, $text])
                        <li class="rounded-2xl bg-slate-900/[0.04] p-3.5 dark:bg-white/[0.04]">
                            <span class="grid size-[22px] place-items-center rounded-full bg-slate-900 text-xs font-extrabold text-white dark:bg-slate-100 dark:text-slate-900">{{ $loop->iteration }}</span>
                            <h3 class="mt-2.5 text-[14.5px] font-extrabold">{{ $step }}</h3>
                            <p class="mt-1 text-[12.5px] leading-normal text-slate-500 dark:text-slate-400">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-tile>

            {{-- ── Readouts ───────────────────────────────────────── --}}
            {{-- Temperature is the sky's; noise only when the last day holds some. --}}
            @if ($this->hasReadings)
                @php($readouts = array_filter([
                    ['key' => 'h', 'label' => 'Humidity', 'icon' => 'droplet', 'unit' => '%', 'dec' => 2, 'accent' => 'text-cyan-600 dark:text-cyan-400'],
                    ['key' => 'p', 'label' => 'Pressure, MSL', 'icon' => 'gauge', 'unit' => 'hPa', 'dec' => 1, 'accent' => 'text-violet-600 dark:text-violet-400'],
                    ['key' => 'n', 'label' => 'Noise, LAeq', 'icon' => 'audio-waveform', 'unit' => 'dB(A)', 'dec' => 1, 'accent' => 'text-emerald-600 dark:text-emerald-400'],
                ], fn (array $readout): bool => isset($this->metrics[$readout['key']])))
                @foreach ($readouts as $readout)
                    @php($m = $this->metrics[$readout['key']])
                    @php($trace = \App\ValueObject\Trace::spanning($m['trace']))
                    <x-tile
                        :title="$readout['label']"
                        :icon="$readout['icon']"
                        :tone="$tones[$readout['key']]"
                        aria-label="{{ $readout['label'] }} now"
                        :class="\Illuminate\Support\Arr::toCssClasses(['col-span-12 flex flex-col', 'md:col-span-4' => count($readouts) === 3, 'md:col-span-6' => count($readouts) === 2])"
                    >
                        <p class="text-[44px] leading-none font-extrabold tracking-[-0.045em] tabular-nums">
                            {{ number_format($m['now'], $readout['dec'], ',', ' ') }}<span class="{{ $readout['accent'] }} ml-1 text-base font-bold tracking-normal">{{ $readout['unit'] }}</span>
                        </p>
                        <p class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-[13px] text-slate-500 dark:text-slate-400">
                            <span class="font-mono tabular-nums">24 h · min {{ number_format($m['dayMin'], $readout['dec'], ',', ' ') }} · max {{ number_format($m['dayMax'], $readout['dec'], ',', ' ') }}</span>
                            <span class="{{ $readout['accent'] }} inline-flex items-center gap-1 font-mono font-medium">
                                <flux:icon
                                    :icon="$m['delta'] >= 0.05 ? 'arrow-trending-up' : ($m['delta'] <= -0.05 ? 'arrow-trending-down' : 'minus')"
                                    variant="micro"
                                />
                                {{ ($m['delta'] >= 0 ? '+' : '−') . number_format(abs($m['delta']), $readout['dec'], ',', ' ') }}/h
                            </span>
                        </p>
                        {{-- The last day's means, drawn on the server: a canvas for one line would be overkill. --}}
                        <svg
                            viewBox="0 0 100 100"
                            preserveAspectRatio="none"
                            class="{{ $readout['accent'] }} -mx-4 -mb-4 mt-auto h-14 w-[calc(100%+2rem)] overflow-visible pt-3 sm:-mx-5 sm:-mb-[18px] sm:w-[calc(100%+2.5rem)]"
                            data-spark="{{ $readout['key'] }}"
                            aria-hidden="true"
                        >
                            <defs>
                                <linearGradient id="spark-{{ $readout['key'] }}" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="currentColor" stop-opacity="0.25" />
                                    <stop offset="1" stop-color="currentColor" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <path d="{{ $trace->area() }}" fill="url(#spark-{{ $readout['key'] }})" />
                            <path d="{{ $trace->line() }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                        </svg>
                    </x-tile>
                @endforeach
            @endif

            {{-- ── Scoring ────────────────────────────────────────── --}}
            {{-- With the forecast: gone while it is stale, like the sky's hours. Starts folded:
                 rendered hidden, so nothing flashes before Alpine runs. --}}
            @if ($forecast !== null && $this->accuracyScore !== null)
                @php($score = $this->accuracyScore)
                @php($scoredHours = array_column($this->forecastAccuracy, 'hours'))
                @php($figures = array_filter([
                    ['label' => 'With correction', 'figures' => $score['corrected']],
                    ['label' => 'Base model', 'figures' => $score['base']],
                ], fn (array $row): bool => $row['figures'] !== null))
                <x-tile
                    title="How the forecast scores"
                    icon="presentation-chart-line"
                    :tone="$tones['t']"
                    hint="Last {{ $this::ACCURACY_DAYS }} days, hour by hour ahead"
                    aria-label="Forecast accuracy"
                    class="col-span-12"
                    x-data="{ collapsed: true }"
                >
                    <x-slot:actions>
                        <x-fold-button controls="forecast-accuracy" label="Forecast accuracy" :collapsed="true" />
                    </x-slot:actions>

                    <div id="forecast-accuracy" class="hidden" x-bind:class="{ hidden: collapsed }">
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                            <p class="flex items-center gap-2 text-[13px] font-semibold text-slate-500 dark:text-slate-400">
                                <span class="size-2 rounded-full bg-amber-600 dark:bg-amber-500" aria-hidden="true"></span>
                                With correction
                            </p>
                            <p class="flex items-center gap-2 text-[13px] font-semibold text-slate-500 dark:text-slate-400">
                                <span class="w-3 border-t border-dashed border-slate-400" aria-hidden="true"></span>
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
                            class="pt-2"
                            data-accuracy-chart="days"
                            data-accuracy-rows="{{ json_encode($score['days']) }}"
                            aria-label="Temperature {{ $score['hours'] }} h ahead by day: how much smaller the miss was than the naive guess's, with the station correction and without"
                            role="img"
                        >
                            <div wire:ignore data-accuracy-canvas class="h-40 w-full"></div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full font-mono text-xs tabular-nums">
                                <thead>
                                    <tr class="text-left font-sans text-[12.5px] text-slate-500 dark:text-slate-400">
                                        <th class="py-2 pr-6 font-semibold">+{{ $score['hours'] }} h</th>
                                        <th class="py-2 pr-6 font-semibold">Better by</th>
                                        <th class="py-2 pr-6 font-semibold">Off by · naive</th>
                                        <th class="py-2 pr-6 font-semibold">In range</th>
                                        <th class="py-2 pr-6 font-semibold">Range width</th>
                                        <th class="py-2 font-semibold">Forecasts</th>
                                    </tr>
                                </thead>
                                <tbody class="text-slate-800 dark:text-slate-200">
                                    @foreach ($figures as $row)
                                        @php($shown = $row['figures'])
                                        <tr class="border-t border-slate-900/5 dark:border-white/5">
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

                        <p class="pt-2 font-mono text-xs text-slate-800 dark:text-slate-200">
                            <span class="font-sans text-[12.5px] font-semibold text-slate-500 dark:text-slate-400">Rain chance · rained / dry</span>
                            <span class="ml-2 tabular-nums">
                                @if ($score['rain']['count'] === 0)
                                    <span class="text-slate-500 dark:text-slate-400">not listened</span>
                                @else
                                    {{ $score['rain']['chanceWhenRain'] === null ? 'no rain' : number_format($score['rain']['chanceWhenRain'], 0).' %' }}
                                    /
                                    {{ $score['rain']['chanceWhenDry'] === null ? 'no dry spell' : number_format($score['rain']['chanceWhenDry'], 0).' %' }}
                                @endif
                            </span>
                        </p>

                        {{-- The hour-of-day detail starts closed: rendered hidden, so nothing flashes before Alpine runs. --}}
                        <div x-data="{ open: false }" class="pt-3">
                            <flux:button
                                x-on:click="open = ! open"
                                variant="subtle"
                                size="xs"
                                icon="presentation-chart-line"
                                aria-expanded="false"
                                x-bind:aria-expanded="open ? 'true' : 'false'"
                                aria-controls="forecast-accuracy-hours"
                                x-bind:class="{ 'text-slate-800! dark:text-white!': open }"
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

                        <p class="pt-3 text-[12.5px] leading-relaxed text-slate-500 dark:text-slate-400">
                            Better by: how much smaller the forecast's miss was than the naive guess's - the temperature at the time of the forecast, kept for the hours ahead.
                            Above zero the forecast beats the guess, below zero it does worse.
                            Base model: the forecast as trained on ČHMÚ records, before the correction this station's own misses teach it;
                            the gap between the two lines is what the correction has learnt.
                            In range: a good range holds eight readings in ten, so 80 % is on target and 100 % means it was drawn too wide.
                            Rain as the INMP441 microphone heard it: the mean chance given when it rained and when it stayed dry. Drizzle is not heard.
                            By hour of the day: measured minus the middle of the forecast shown, above zero warmer than forecast.
                        </p>
                    </div>
                </x-tile>
            @endif

            {{-- ── Range and navigator ────────────────────────────── --}}
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

            {{-- ── Channel strips ─────────────────────────────────── --}}
            @unless ($this->hasReadings)
                <section aria-label="No data" class="tile col-span-12 py-12 text-center">
                    <p class="text-[17px] font-extrabold">Nothing in this range</p>
                    <flux:text class="mx-auto mt-3 max-w-sm text-sm">
                        No reading was recorded between {{ $this->window['from'] }} and
                        {{ $this->window['to'] }}. Pick a wider range, or reset the zoom.
                    </flux:text>
                </section>
            @endunless

            {{-- Pressure has its own strip: a 40 hPa spread is a flat line beside
                 the others. The dew point is derived, so it starts off. --}}
            @php($hasNoise = $this->noise !== [])
            @php($strips = [
                [
                    'key' => 'th',
                    'label' => 'Temperature & humidity',
                    'title' => 'Temperature and humidity',
                    'icon' => 'thermometer',
                    'tone' => $tones['t'],
                    'hint' => 'means with the spread of the samples',
                    'height' => 'h-72 sm:h-80',
                    'span' => 'col-span-12',
                    'channels' => [
                        ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dot' => 'bg-amber-600 dark:bg-amber-500', 'chip' => 'bg-amber-500/15 text-amber-700 dark:text-amber-400', 'toggle' => true],
                        ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dot' => 'bg-cyan-600 dark:bg-cyan-400', 'chip' => 'bg-cyan-500/15 text-cyan-700 dark:text-cyan-400', 'toggle' => true],
                        ['key' => 'd', 'label' => 'Dew point', 'unit' => '°C', 'dot' => 'bg-pink-600 dark:bg-pink-400', 'chip' => 'bg-pink-500/15 text-pink-700 dark:text-pink-400', 'toggle' => true],
                    ],
                ],
                [
                    'key' => 'p',
                    'label' => 'Pressure, MSL',
                    'title' => 'Pressure',
                    'icon' => 'gauge',
                    'tone' => $tones['p'],
                    'hint' => 'reduced to sea level',
                    'height' => 'h-48 sm:h-56',
                    'span' => $hasNoise ? 'col-span-12 lg:col-span-6' : 'col-span-12',
                    'channels' => [
                        ['key' => 'p', 'label' => 'Pressure, MSL', 'unit' => 'hPa', 'dot' => 'bg-violet-600 dark:bg-violet-500', 'chip' => 'bg-violet-500/15 text-violet-700 dark:text-violet-400'],
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
                <x-strip
                    :key="$strip['key']"
                    :label="$strip['label']"
                    :title="$strip['title']"
                    :icon="$strip['icon']"
                    :tone="$strip['tone']"
                    :hint="$strip['hint']"
                    :height="$strip['height']"
                    :span="$strip['span']"
                >
                    @foreach ($strip['channels'] as $channel)
                        @if (isset($channel['toggle']))
                            {{-- The chip is the switch; the last one on is disabled instead. --}}
                            @php($shown = $this->channels[$channel['key']] ?? false)
                            @php($last = $this->isLastChannel($channel['key']))
                            <button
                                type="button"
                                wire:click="toggleChannel('{{ $channel['key'] }}')"
                                aria-pressed="{{ $shown ? 'true' : 'false' }}"
                                @disabled($last)
                                @class([
                                    'chip focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 dark:focus-visible:outline-slate-100',
                                    $channel['chip'] => $shown,
                                    'cursor-pointer' => ! $last,
                                    'cursor-default' => $last,
                                    'bg-slate-900/[0.04] text-slate-400 hover:text-slate-600 dark:bg-white/[0.04] dark:text-slate-500 dark:hover:text-slate-300' => ! $shown,
                                ])
                            >
                                <span
                                    @class([
                                        'size-2 rounded-full',
                                        $channel['dot'] => $shown,
                                        'bg-slate-400/50' => ! $shown,
                                    ])
                                    aria-hidden="true"
                                ></span>
                                {{ $channel['label'] }} ({{ $channel['unit'] }})
                            </button>
                        @else
                            <p class="chip {{ $channel['chip'] }}">
                                <span class="{{ $channel['dot'] }} size-2 rounded-full" aria-hidden="true"></span>
                                {{ $channel['label'] }} ({{ $channel['unit'] }})
                            </p>
                        @endif
                    @endforeach
                </x-strip>
            @endforeach

            {{-- ── Noise ──────────────────────────────────────────── --}}
            {{-- Protocol 3 only: a window of older rows has no noise, and no strips. --}}
            @if ($hasNoise)
                <x-strip
                    key="noise"
                    label="Noise"
                    title="Noise"
                    icon="audio-waveform"
                    :tone="$tones['n']"
                    hint="dB(A)"
                    height="h-48 sm:h-56"
                    span="col-span-12 lg:col-span-6"
                >
                    <p class="chip bg-emerald-500/15 text-emerald-700 dark:text-emerald-400">
                        <span class="size-2 rounded-full bg-emerald-600 dark:bg-emerald-400" aria-hidden="true"></span>
                        LAeq
                    </p>
                    <p class="chip bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
                        <span class="size-2 rounded-full bg-emerald-600/25 dark:bg-emerald-400/25" aria-hidden="true"></span>
                        LA90 to LA10
                    </p>
                    <p class="chip bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
                        <span class="size-2 rounded-full bg-emerald-600/60 dark:bg-emerald-400/60" aria-hidden="true"></span>
                        LAmax
                    </p>
                </x-strip>

                <x-strip
                    key="spectrum"
                    label="Noise spectrum"
                    title="Noise spectrum"
                    icon="audio-waveform"
                    :tone="$tones['spectrum']"
                    hint="dB, third octaves 25 Hz to 8 kHz"
                    height="h-72 sm:h-80"
                >
                    {{-- The scale's ends are the window's own quietest and loudest band; station-charts.js fills them in. --}}
                    <p class="flex items-center gap-2 font-mono text-xs text-slate-500 dark:text-slate-400">
                        <span data-spectrum-low wire:ignore></span>
                        <span data-spectrum-scale wire:ignore class="h-2 w-24 rounded-full" aria-hidden="true"></span>
                        <span data-spectrum-high wire:ignore></span>
                    </p>
                </x-strip>
            @endif

            {{-- ── Payload tail ───────────────────────────────────── --}}
            @php($hasReport = $this->stationReport !== null)
            @if ($this->recentTransmissions !== [])
                @php($transmissionCount = count($this->recentTransmissions))
                <x-tile
                    title="Last transmission"
                    icon="code-xml"
                    aria-label="Last transmissions"
                    :class="\Illuminate\Support\Arr::toCssClasses(['col-span-12', 'lg:col-span-7' => $hasReport])"
                    x-data="{ all: false }"
                >
                    <x-slot:actions>
                        {{-- Counts what is on screen: folded, that is the newest one alone. --}}
                        <p class="text-[13px] font-medium text-slate-500 dark:text-slate-400">
                            <span x-bind:class="{ hidden: all }">Last measurement · when it arrived</span>
                            @if ($transmissionCount > 1)
                                <span class="hidden" x-bind:class="{ hidden: ! all }">Last {{ $transmissionCount }} measurements · when they arrived</span>
                            @endif
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
                    </x-slot:actions>

                    <p class="mb-2 font-mono text-xs text-slate-500 dark:text-slate-400">
                        POST /api/v1/measurement · 0,01 °C · 0,01 % · Pa · UTC unix · samples
                    </p>

                    <div id="transmissions" class="grid gap-2">
                        @foreach ($this->recentTransmissions as $packet)
                            <div
                                @class([
                                    'grid gap-x-8 gap-y-2 rounded-2xl bg-slate-900/[0.04] px-4 py-3 dark:bg-black/25',
                                    'hidden' => ! $loop->first,
                                ])
                                @unless ($loop->first)
                                    x-bind:class="{ hidden: ! all }"
                                @endunless
                            >
                                {{-- The blob as stored, one field per line: a V2 packet on
                                     one line outruns a desktop. Coloured by the key's first word.
                                     V3's "noise" object stays on its line as JSON. --}}
                                <p class="overflow-x-auto font-mono text-xs leading-relaxed text-slate-500 tabular-nums dark:text-slate-400">
                                    <span class="block text-slate-400 dark:text-slate-600">{</span>
                                    <span class="block pl-4">"timestamp": <span class="text-slate-700 dark:text-slate-300">{{ $packet['timestamp'] }}</span><span class="text-slate-400 dark:text-slate-600">,</span></span>
                                    @foreach ($packet['packet'] as $field => $value)
                                        @php($accent = match (strtok($field, '_')) {
                                            'temperature' => 'text-amber-600',
                                            'humidity' => 'text-cyan-600',
                                            'pressure' => 'text-violet-600 dark:text-violet-500',
                                            default => 'text-zinc-700 dark:text-zinc-300',
                                        })
                                        <span class="block pl-4">"{{ $field }}": <span class="{{ $accent }}{{ is_array($value) ? ' break-all' : '' }}">{{ is_array($value) ? json_encode($value) : $value }}</span>@unless ($loop->last)<span class="text-slate-400 dark:text-slate-600">,</span>@endunless</span>
                                    @endforeach
                                    <span class="block text-slate-400 dark:text-slate-600">}</span>
                                </p>

                                <p class="flex flex-wrap gap-x-4 border-t border-slate-900/5 pt-2 font-mono text-xs text-slate-500 tabular-nums dark:border-white/5 dark:text-slate-400">
                                    <span>{{ $packet['at'] }}</span>
                                    <span><span class="text-slate-800 dark:text-slate-200">{{ number_format($packet['t'], 2, ',', ' ') }}</span> °C</span>
                                    <span><span class="text-slate-800 dark:text-slate-200">{{ number_format($packet['h'], 2, ',', ' ') }}</span> %</span>
                                    <span><span class="text-slate-800 dark:text-slate-200">{{ number_format($packet['p'], 1, ',', ' ') }}</span> hPa MSL</span>
                                    <span class="hidden md:inline">{{ $packet['ago'] }}</span>
                                </p>
                            </div>
                        @endforeach
                    </div>
                </x-tile>
            @endif

            {{-- ── Station report ─────────────────────────────────── --}}
            @if ($hasReport)
                @php($report = $this->stationReport)
                <x-tile
                    title="Station"
                    icon="cpu"
                    hint="as reported with the last upload"
                    aria-label="Station report"
                    :class="\Illuminate\Support\Arr::toCssClasses(['col-span-12', 'lg:col-span-5' => $this->recentTransmissions !== []])"
                >
                    <x-slot:actions>
                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $report['at'] }} · {{ $report['ago'] }}</p>
                    </x-slot:actions>

                    {{-- Board only from firmware 2.3, clock rows only once the board has measured a drift. --}}
                    <dl class="grid grid-cols-2 gap-2">
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
                            <div class="min-w-0 rounded-[14px] bg-slate-900/[0.04] px-3 py-2.5 dark:bg-white/[0.04]">
                                <dt class="text-xs text-slate-500 first-letter:uppercase dark:text-slate-400">{{ $label }}</dt>
                                <dd class="mt-0.5 truncate font-mono text-[13px] font-medium tabular-nums">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-tile>
            @endif

            {{-- ── Site location ──────────────────────────────────── --}}
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
        </div>

        {{-- ── Footer ─────────────────────────────────────────────── --}}
        <footer class="mt-6 flex flex-wrap items-center justify-between gap-2 px-1.5 text-[13px] text-slate-500 dark:text-slate-400">
            <span>{{ number_format($this->recordCount, 0, ',', ' ') }} records</span>
            <span>
                &copy; {{ $this->currentYear }} Vladislav Rajtmajer ·
                <a
                    href="https://github.com/rajtik76"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-sm underline decoration-slate-300 underline-offset-4 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 dark:decoration-slate-600 dark:hover:text-slate-300 dark:focus-visible:outline-slate-100"
                >GitHub</a>
            </span>
        </footer>
    </div>
</div>
