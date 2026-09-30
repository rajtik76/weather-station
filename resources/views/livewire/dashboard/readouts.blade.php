{{-- Temperature is the sky's; noise and light only when the last day holds some.
     The light's sparkline is logarithmic: on a linear one every night is the floor. --}}
@if ($this->hasReadings)
    @php($readouts = array_filter([
        ['key' => 'h', 'label' => 'Humidity', 'icon' => 'droplet', 'unit' => '%', 'dec' => 2, 'accent' => 'text-cyan-600 dark:text-cyan-400'],
        ['key' => 'p', 'label' => 'Pressure, MSL', 'icon' => 'gauge', 'unit' => 'hPa', 'dec' => 1, 'accent' => 'text-violet-600 dark:text-violet-400'],
        ['key' => 'n', 'label' => 'Noise, LAeq', 'icon' => 'audio-waveform', 'unit' => 'dB(A)', 'dec' => 1, 'accent' => 'text-emerald-600 dark:text-emerald-400'],
        ['key' => 'l', 'label' => 'Light, in the shield', 'icon' => 'sun', 'unit' => 'lx', 'dec' => 1, 'accent' => 'text-yellow-600 dark:text-yellow-400', 'log' => true],
    ], fn (array $readout): bool => isset($this->metrics[$readout['key']])))
    @foreach ($readouts as $readout)
        @php($m = $this->metrics[$readout['key']])
        @php($trace = \App\ValueObject\Trace::spanning(isset($readout['log']) ? array_map(fn (float $value): float => log10(max($value, 0.01)), $m['trace']) : $m['trace']))
        <x-tile
            :title="$readout['label']"
            :icon="$readout['icon']"
            :tone="$tones[$readout['key']]"
            aria-label="{{ $readout['label'] }} now"
            :class="\Illuminate\Support\Arr::toCssClasses([
                'col-span-12 flex flex-col',
                'md:col-span-6 xl:col-span-3' => count($readouts) === 4,
                'md:col-span-4' => count($readouts) === 3,
                'md:col-span-6' => count($readouts) === 2,
            ])"
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
