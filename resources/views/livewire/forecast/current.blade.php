{{-- The same chart as the overview's, here with when it was made. --}}
@php($chart = $this->forecastChart)
<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="current-h">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
        <h2 id="current-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Current forecast</h2>
        @if ($chart !== null)
            <p class="m-0 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[13px] text-ink-3">
                <span>made {{ $this->forecast['at'] }} · {{ $this->forecast['corrected'] ? 'fitted to this station' : 'not yet fitted to this station' }}</span>
                <span class="flex items-center gap-2"><span class="swatch bg-ch1"></span>measured</span>
                <span class="flex items-center gap-2"><svg width="16" height="2" aria-hidden="true"><line x1="0" y1="1" x2="16" y2="1" stroke="var(--ch1)" stroke-width="2" stroke-dasharray="4 3" /></svg>median</span>
                <span class="flex items-center gap-2"><span class="inline-block h-2.5 w-4 rounded-[2px] bg-ch1/20"></span>10-90 %</span>
            </p>
        @endif
    </div>

    @if ($chart === null)
        <p class="m-0 rounded-[10px] border border-line bg-screen px-6 py-10 text-ink-2">No forecast from the current readings yet.</p>
    @else
        <x-forecast-fan :chart="$chart" :horizons="$this->forecast['horizons']" />
    @endif
</section>
