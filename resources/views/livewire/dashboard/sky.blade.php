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
                 Noise and light only while the newest reading has them: a dead sensor leaves the day's last value behind. --}}
            @php($liveReadouts = array_filter([
                ['key' => 'h', 'label' => 'Humidity', 'icon' => 'droplet', 'unit' => '%', 'dec' => 2],
                ['key' => 'p', 'label' => 'Pressure', 'icon' => 'gauge', 'unit' => 'hPa', 'dec' => 1],
                ['key' => 'n', 'label' => 'Noise', 'icon' => 'audio-waveform', 'unit' => 'dB(A)', 'dec' => 1],
                ['key' => 'l', 'label' => 'Light', 'icon' => 'sun', 'unit' => 'lx', 'dec' => 1],
            ], fn (array $readout): bool => isset($this->metrics[$readout['key']])
                && ($readout['key'] !== 'n' || $this->isNoiseCurrent)
                && ($readout['key'] !== 'l' || $this->isLightCurrent)))
            {{-- Four readouts go by the hero column's own width, not the viewport's: a row of
                 equal quarters overflowed "1 020,2 hPa" in the half-width column. Below @xl two rows
                 with a rule drawn in the middle of the gap, so the dividers stop short of it as they
                 do of the outer borders; above it one row, each column as wide as its value.
                 divide-x draws on the right of every item but the last, so the row's end drops it. --}}
            <div class="@container">
                <dl
                    @class([
                        'mt-5 grid divide-x divide-white/20 border-y border-white/20 py-3',
                        'max-w-lg' => count($liveReadouts) < 4,
                        'grid-cols-2 gap-y-6 @xl:grid-cols-[repeat(4,max-content)] @xl:gap-y-0 @max-xl:[&>*:nth-child(2)]:border-e-0 @max-xl:[&>*:nth-child(3)]:pl-0 @max-xl:[&>*:nth-child(n+3)]:relative @max-xl:[&>*:nth-child(n+3)]:before:absolute @max-xl:[&>*:nth-child(n+3)]:before:inset-x-0 @max-xl:[&>*:nth-child(n+3)]:before:-top-3 @max-xl:[&>*:nth-child(n+3)]:before:h-px @max-xl:[&>*:nth-child(n+3)]:before:bg-white/20' => count($liveReadouts) === 4,
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
            </div>

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
        @include('livewire.dashboard.forecast')
    @endif
</section>

{{-- Credit for the sky pictures, under the widget as a photo's would be. Always shown:
     it belongs to the pictures, not to whether the station is on the air. --}}
<p class="col-span-12 -mt-1.5 px-2 text-right text-xs text-slate-500 sm:-mt-2 dark:text-slate-400" data-sky-credit>
    Sky image generated with OpenAI
</p>
