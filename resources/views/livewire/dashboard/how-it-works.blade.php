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
            ['Measure', 'An SHT4x, a VEML7700 and an INMP441 microphone outside, a BMP280 indoors, read every 30 seconds.'],
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
