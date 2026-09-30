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
    data-light-rows="{{ json_encode($this->light) }}"
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
{{-- Protocol 3 only: a sensor that never sent noise has no strips; a window before it has them empty. --}}
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

{{-- ── Light ──────────────────────────────────────────── --}}
{{-- Protocol 4 only, and only for a sensor that ever sent light. The VEML7700 sits behind
     the shield's louvers, so the lux are the shield's, not the open sky's: read the shape. --}}
@if ($this->light !== [])
    <x-strip
        key="light"
        label="Light"
        title="Light"
        icon="sun"
        :tone="$tones['l']"
        hint="lx inside the radiation shield, log scale"
        height="h-48 sm:h-56"
    >
        <p class="chip bg-yellow-500/15 text-yellow-700 dark:text-yellow-400">
            <span class="size-2 rounded-full bg-yellow-600 dark:bg-yellow-400" aria-hidden="true"></span>
            Light (lx)
        </p>
    </x-strip>
@endif
