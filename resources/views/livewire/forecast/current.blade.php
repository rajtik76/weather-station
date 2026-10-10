@php($chart = $this->forecastChart)
<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="current-h">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
        <h2 id="current-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Current forecast</h2>
        @if ($chart !== null)
            <p class="m-0 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[13px] text-ink-3">
                <span>made {{ $this->forecast['at'] }} · {{ $this->forecast['corrected'] ? 'each hour from the model leading the race' : 'correction not yet fitted to this station' }}</span>
                <x-forecast-legend />
            </p>
        @endif
    </div>

    @if ($chart === null)
        <p class="m-0 rounded-[10px] border border-line bg-screen px-6 py-10 text-ink-2">No forecast from the current readings yet.</p>
    @else
        <x-forecast-fan :chart="$chart" :horizons="$this->forecast['horizons']" :show-model="true" />
    @endif
</section>
