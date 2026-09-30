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
            An SHT4x outside and a BMP280 indoors are read every thirty seconds by an ESP32, which reports each ten minutes as a mean with its extremes.
            An INMP441 microphone beside the SHT4x adds the noise: A-weighted levels and a third-octave spectrum per window, and a VEML7700 in the same shield the light that gets through its louvers.
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
