{{-- One card per channel the last day holds; noise and light only for a
     station that sends them. The light's sparkline is logarithmic: on a
     linear one every night is the floor. --}}
@php($channels = array_filter([
    ['key' => 't', 'ch' => 'CH1', 'colour' => 'ch1', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 1, 'note' => 'SHT4x, in the shield.'.($this->dewPoint === null ? '' : ' Dew point '.\App\ValueObject\Figure::format($this->dewPoint, 1).' °C.')],
    ['key' => 'h', 'ch' => 'CH2', 'colour' => 'ch2', 'label' => 'Humidity', 'unit' => '%', 'dec' => 1, 'note' => 'SHT4x, relative humidity.'],
    ['key' => 'p', 'ch' => 'CH3', 'colour' => 'ch3', 'label' => 'Pressure, MSL', 'unit' => 'hPa', 'dec' => 1, 'note' => 'BMP280 indoors, reduced to sea level.'],
    ['key' => 'n', 'ch' => 'CH4', 'colour' => 'ch4', 'label' => 'Noise, LAeq', 'unit' => 'dB(A)', 'dec' => 1, 'note' => match ($this->rainHeard) {
        true => 'INMP441 in the shield. Rain heard now.',
        false => 'INMP441 in the shield. No rain heard.',
        null => 'INMP441 in the shield.',
    }],
    ['key' => 'l', 'ch' => 'AUX', 'colour' => 'aux', 'label' => 'Light, in the shield', 'unit' => 'lx', 'dec' => 0, 'note' => 'VEML7700, behind the louvers.', 'log' => true],
], fn (array $channel): bool => isset($this->metrics[$channel['key']])))

@if ($channels !== [])
    <section class="page-wrap mt-20 sm:mt-24" aria-labelledby="ch-h">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-1">
            <h2 id="ch-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Channels</h2>
            <p class="m-0 font-mono text-[14px] text-ink-3">last 24 h · 10 min windows · min / max over 24 h</p>
        </div>

        <div @class([
            'grid grid-cols-1 gap-px overflow-hidden rounded-[10px] border border-line bg-line sm:grid-cols-2',
            'lg:grid-cols-5' => count($channels) === 5,
            'lg:grid-cols-4' => count($channels) === 4,
            'lg:grid-cols-3' => count($channels) === 3,
        ])>
            @foreach ($channels as $channel)
                @php($figures = $this->metrics[$channel['key']])
                @php($trace = \App\ValueObject\Trace::spanning(isset($channel['log']) ? array_map(fn (float $value): float => log10(max($value, 0.01)), $figures['trace']) : $figures['trace']))
                @php($newest = $trace->points[array_key_last($trace->points)])
                @php($silent = in_array($channel['key'], $this->silentChannels, true))
                <article class="flex min-w-0 flex-col bg-screen p-5">
                    <div class="flex items-center justify-between">
                        <h3 class="m-0 flex items-center gap-2.5 text-[16px] font-medium text-ink-2"><span class="swatch" style="background: var(--{{ $channel['colour'] }})"></span>{{ $channel['label'] }}</h3>
                        <span class="font-mono text-[13px] text-ink-3">{{ $channel['ch'] }}</span>
                    </div>
                    <p @class(['num m-0 mt-4 font-mono text-[36px] leading-none font-medium tracking-[-0.02em]', 'text-ink-3' => $silent])>{{ \App\ValueObject\Figure::format($figures['now'], $channel['dec']) }}<span class="ml-1 text-[16px] font-normal tracking-normal text-ink-3">{{ $channel['unit'] }}</span></p>
                    <div class="relative mt-5 h-10" aria-hidden="true">
                        <svg class="layer" viewBox="0 0 100 100" preserveAspectRatio="none">
                            <path d="{{ $trace->line() }}" style="fill: none; stroke: var(--{{ $channel['colour'] }}); stroke-width: 1.6px; stroke-linejoin: round; stroke-linecap: round" />
                        </svg>
                        @unless ($silent)
                            <span class="absolute h-[7px] w-[7px] rounded-full" style="right: -3.5px; top: calc({{ $newest['y'] }}% - 3.5px); background: var(--{{ $channel['colour'] }})"></span>
                        @endunless
                    </div>
                    <dl class="num m-0 mt-4 grid grid-cols-3 gap-2 font-mono text-[14px]">
                        <div><dt class="text-[12.5px] text-ink-3">min</dt><dd class="m-0">{{ \App\ValueObject\Figure::format($figures['dayMin'], $channel['dec']) }}</dd></div>
                        <div><dt class="text-[12.5px] text-ink-3">max</dt><dd class="m-0">{{ \App\ValueObject\Figure::format($figures['dayMax'], $channel['dec']) }}</dd></div>
                        <div><dt class="text-[12.5px] text-ink-3">Δ/h</dt><dd class="m-0">{{ \App\ValueObject\Figure::signed($figures['delta'], $channel['dec']) }}</dd></div>
                    </dl>
                    <p class="m-0 mt-4 text-[14px] leading-snug text-ink-3">
                        @if ($silent)
                            Missing from the newest window: the figure is the last one read.
                        @endif
                        {{ $channel['note'] }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
