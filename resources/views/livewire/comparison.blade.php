<div class="instrument min-h-screen">
    @include('livewire.overview.header', ['page' => 'comparison'])

    <main id="main" class="page-wrap pt-10 sm:pt-14">
        <section aria-labelledby="comparison-h">
            <div class="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <p class="label-mono m-0 mb-2">Shield correction · reference check</p>
                    <h1 id="comparison-h" class="m-0 font-display text-[30px] leading-tight font-semibold sm:text-[38px]">Against Plzeň-Mikulka</h1>
                </div>
                <div class="flex items-end gap-2" role="group" aria-label="Comparison date">
                    <flux:button wire:click="moveDay(-1)" :disabled="$this->day->date === \App\ValueObject\ComparisonDay::earliest()" aria-label="Previous day">←</flux:button>
                    <flux:input type="date" wire:model.change.live="date" label="Date" :min="\App\ValueObject\ComparisonDay::earliest()" :max="\App\ValueObject\ComparisonDay::today()" />
                    <flux:button wire:click="moveDay(1)" :disabled="$this->day->date === \App\ValueObject\ComparisonDay::today()" aria-label="Next day">→</flux:button>
                </div>
            </div>
            <p class="mt-4 max-w-3xl text-[15px] leading-relaxed text-ink-2">The ČHMÚ reference minus the calculated shade temperature on one local calendar day. Choose a date up to 30 days back.</p>
            @include('livewire.comparison.candidate', ['candidate' => $this->latestCandidate])
            <div data-chart-rows="[]" data-comparison-rows="{{ json_encode($this->comparisonRows) }}" hidden></div>
            <div class="mt-6 rounded-[10px] border border-line bg-screen px-3 py-5 sm:px-5" wire:loading.class="opacity-60" wire:target="date,moveDay,sensor">
                <x-channel-strip key="comparison" label="Mikulka vs shade estimate" channel="°C" height="h-72 sm:h-96">
                    <span class="flex items-center gap-2 text-ink-2"><span class="swatch bg-ch3" aria-hidden="true"></span>Mikulka − shade estimate</span>
                </x-channel-strip>
                @if (! collect($this->comparisonRows)->contains(fn (array $row): bool => $row[1] !== null && $row[2] !== null))
                    <p class="m-0 py-4 text-center text-[15px] text-ink-2">No matched observations for this day yet.</p>
                @endif
            </div>
            <p class="mt-3 text-[13px] leading-relaxed text-ink-3">Ten-minute slots, Europe/Prague. Positive means Mikulka is warmer. Gaps mean missing data or an unavailable correction. ČHMÚ observations can arrive later. Days when the clocks change contain 23 or 25 hours.</p>
            @if ($this->dayCandidate && collect($this->comparisonRows)->contains(fn (array $row): bool => $row[1] !== null && $row[2] !== null && $row[3] !== null))
                <div class="mt-6 rounded-[10px] border border-line bg-screen px-3 py-5 sm:px-5" wire:loading.class="opacity-60" wire:target="date,moveDay,sensor">
                    <x-channel-strip key="improvement" label="Retrained model improvement" channel="°C" height="h-56 sm:h-72">
                        <span class="flex items-center gap-2 text-ink-2"><span class="swatch bg-ch2" aria-hidden="true"></span>Improvement of model #{{ $this->dayCandidate->id }} · {{ $this->dayCandidate->status() }} over the model in use</span>
                    </x-channel-strip>
                </div>
                <p class="mt-3 text-[13px] leading-relaxed text-ink-3">|Mikulka − model in use| − |Mikulka − model #{{ $this->dayCandidate->id }}| in the same ten-minute slots. Positive means the retrained model is closer to Mikulka, negative that it is further away. Drawn only on the model's own validation days.</p>
            @endif
        </section>

        @php($summary = $this->summary)
        <section class="mt-12" aria-labelledby="deviation-h">
            <p class="label-mono m-0 mb-2">Last 30 days · independent of selected date</p>
            <h2 id="deviation-h" class="m-0 font-display text-[26px] font-semibold">Deviation from Mikulka</h2>
            <p class="mt-3 text-[15px] text-ink-2">{{ \App\ValueObject\Figure::format($summary['count'], 0) }} matched observations. Both columns use the same observations, within 90 seconds of a usable Mikulka reading, after calibration validation.</p>
            @include('livewire.comparison.deviation-table', [
                'key' => 'deviation',
                'columns' => ['measured' => ['label' => 'Shield sensor', 'class' => 'text-ch1'], 'estimated' => ['label' => 'Shade estimate', 'class' => 'text-ref']],
                'deviations' => $summary,
            ])
            @if ($summary['count'] === 0)
                <p class="mt-4 text-[15px] text-ink-2">No matched corrected observations yet. A validated calibration, light measurements and reference data are needed.</p>
            @endif
            @if ($summary['unavailable'] > 0)
                <p class="mt-3 text-[13px] text-ink-3">{{ \App\ValueObject\Figure::format($summary['unavailable'], 0) }} reference matches excluded because the correction was unavailable.</p>
            @endif
            <p class="mt-4 max-w-3xl text-[14px] leading-relaxed text-ink-3">Signed differences are station minus Mikulka; positive means warmer. Mikulka measures at a different location, so local weather differences are included in these deviations. Historical estimates use only calibrations already validated at the time of measurement.</p>
        </section>

        <section class="mt-12" aria-labelledby="model-history-h">
            <p class="label-mono m-0 mb-2">All calibrations · independent of selected date</p>
            <h2 id="model-history-h" class="m-0 font-display text-[26px] font-semibold">Model history</h2>
            @php($history = $this->modelHistory)
            @if ($history === [])
                <p class="mt-3 text-[15px] text-ink-2">No calibrations yet.</p>
            @else
                @include('livewire.comparison.model-history', ['rows' => $history])
                @if (! collect($history)->contains('in_use_now', true))
                    <p class="mt-4 text-[15px] text-ink-2">No model corrects the page now; the shade estimate is unavailable.</p>
                @endif
                <p class="mt-4 max-w-3xl text-[14px] leading-relaxed text-ink-3">Mean absolute deviation from Mikulka on each model's validation days, against the model that was in use before it, on the observations where both have an estimate. Improvement is the previous model's MAE minus this model's; positive means this model is closer to Mikulka. A model under validation shows its days so far. A dash under the previous model means none was in use; this model's MAE then covers all its validation observations. The highlighted row corrects the page now.</p>
            @endif
        </section>
    </main>

    @include('livewire.overview.footer')
</div>
