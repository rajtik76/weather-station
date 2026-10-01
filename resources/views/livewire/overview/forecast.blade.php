@php($chart = $this->forecastChart)
@php($verdict = $this->verdict)
@php($drop = '<svg class="shrink-0 text-ch2" width="10" height="13" viewBox="0 0 10 13" aria-hidden="true"><path d="M5 .8C5 .8 1 5.8 1 8.6a4 4 0 0 0 8 0C9 5.8 5 .8 5 .8Z" fill="currentColor" /></svg>')
<section class="page-wrap mt-20 grid gap-6 sm:mt-24 lg:grid-cols-[minmax(0,1fr)_340px]" aria-labelledby="fc-h">
    <div class="flex min-w-0 flex-col">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-1">
            <h2 id="fc-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Next six hours</h2>
            @if ($chart !== null)
                <p class="m-0 flex flex-wrap items-center gap-4 font-mono text-[13px] text-ink-3">
                    <span class="flex items-center gap-2"><span class="swatch bg-ch1"></span>measured</span>
                    <span class="flex items-center gap-2"><svg width="16" height="2" aria-hidden="true"><line x1="0" y1="1" x2="16" y2="1" stroke="var(--ch1)" stroke-width="2" stroke-dasharray="4 3" /></svg>median</span>
                    <span class="flex items-center gap-2"><span class="inline-block h-2.5 w-4 rounded-[2px] bg-ch1/20"></span>8 in 10</span>
                    <span class="flex items-center gap-2">{!! $drop !!}rain chance</span>
                </p>
            @endif
        </div>

        @if ($chart === null)
            <p class="m-0 flex-1 rounded-[10px] border border-line bg-screen px-6 py-10 text-ink-2">No forecast from the current readings yet.</p>
        @else
            <x-forecast-fan :chart="$chart" :horizons="$this->forecast['horizons']" />
        @endif
    </div>

    <aside class="flex flex-col rounded-[10px] border border-line bg-screen p-6 lg:mt-[62px]" aria-labelledby="verdict-h">
        <h2 id="verdict-h" class="label-mono m-0 font-normal">Verdict · {{ $verdict['hours'] ?? 6 }} h ahead · last 30 days</h2>
        @if ($verdict === null || ! $verdict['ready'])
            <p class="m-0 mt-5 text-[16px] text-ink-2">Too early to tell. The verdict needs a day of forecasts that have come true.</p>
        @else
            @php($beats = $verdict['skill'] >= 0)
            <p class="num m-0 mt-5 font-mono text-[60px] leading-none font-medium tracking-[-0.03em]">{{ \App\ValueObject\Figure::signed($verdict['skill'], 0) }}<span class="ml-1 text-[24px] font-normal tracking-normal text-ink-3">%</span></p>
            <p class="m-0 mt-3 text-[16px] text-ink-2">skill: the forecast misses by {{ \App\ValueObject\Figure::format(abs($verdict['skill']), 0) }} % {{ $beats ? 'less' : 'more' }} than the naive guess.</p>

            <div class="mt-7 space-y-4">
                <div>
                    <div class="flex items-baseline justify-between gap-3 text-[15px]"><span class="text-ink-2">Forecast, mean miss</span><span class="num font-mono">{{ \App\ValueObject\Figure::format($verdict['error'], 2) }} °C</span></div>
                    <div class="mt-2 h-1.5 rounded-full bg-line"><div class="h-full rounded-full bg-ch1" style="width: {{ $verdict['naive'] > 0 ? min(100, $verdict['error'] / max($verdict['error'], $verdict['naive']) * 100) : 0 }}%"></div></div>
                </div>
                <div>
                    <div class="flex items-baseline justify-between gap-3 text-[15px]"><span class="text-ink-2">Naive guess, mean miss</span><span class="num font-mono">{{ \App\ValueObject\Figure::format($verdict['naive'], 2) }} °C</span></div>
                    <div class="mt-2 h-1.5 rounded-full bg-line"><div class="h-full rounded-full bg-ref" style="width: {{ $verdict['naive'] > 0 ? min(100, $verdict['naive'] / max($verdict['error'], $verdict['naive']) * 100) : 0 }}%"></div></div>
                </div>
                <div>
                    <div class="flex items-baseline justify-between gap-3 text-[15px]"><span class="text-ink-2">Reading inside the range</span><span class="num font-mono">{{ \App\ValueObject\Figure::format($verdict['inRange'], 0) }} %</span></div>
                    <div class="relative mt-2 h-1.5 rounded-full bg-line">
                        <div class="h-full rounded-full bg-ink-2" style="width: {{ min(100, $verdict['inRange']) }}%"></div>
                        <span class="absolute -top-1 h-3.5 w-px bg-ink" style="left: 80%" aria-hidden="true"></span>
                    </div>
                    <p class="m-0 mt-1.5 text-right font-mono text-[12.5px] text-ink-3">target 80 %</p>
                </div>
            </div>
        @endif
        <p class="m-0 mt-auto pt-6"><a class="link-ink text-[16px]" href="{{ route('forecast', $this->sensorQuery()) }}">Scores by horizon and day</a></p>
    </aside>
</section>
