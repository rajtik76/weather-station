{{-- ECharts watches data-accuracy-rows (forecast-accuracy.js); Livewire never touches the canvases. --}}
@php($score = $this->score)
@if ($score !== null)
    @php($experiment = $score['experiment'] ?? null)
    @php($rain = $score['rain'])
    @php($base = '<svg width="16" height="2" aria-hidden="true"><line x1="0" y1="1" x2="16" y2="1" stroke="var(--ref)" stroke-width="2" stroke-dasharray="3 3" /></svg>')
    <section class="page-wrap mt-20 sm:mt-24" aria-labelledby="detail-h">
        <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
            <div class="max-w-[60ch]">
                <h2 id="detail-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Day by day</h2>
                <p class="m-0 mt-3 text-[16px] leading-relaxed text-ink-2">One horizon at a time. "Shown" is the forecast on the page, taken from the model leading the model race for that part of the day; "base" is the model before anything learnt on this station.</p>
                @if ($experiment !== null)
                    @if ($experiment['synthetic'])
                        <p class="m-0 mt-3 text-[15px] leading-relaxed text-ink-3">Synthetic preview: {{ $experiment['version'] }}. These graphs compare demonstration forecasts, not measured experiment performance.</p>
                    @else
                        <p class="m-0 mt-3 text-[15px] leading-relaxed text-ink-3">{{ $experiment['version'] }} is the newest light experiment; it is shown wherever it leads the model race. Its line starts with its first scored forecast; tooltips show how many hours each line was scored on.</p>
                    @endif
                @endif
            </div>
            <div class="seg" role="group" aria-label="Hours ahead">
                @foreach ($this->forecastAccuracy as $scored)
                    <button
                        type="button"
                        wire:click="$set('horizon', {{ $scored['hours'] }})"
                        aria-pressed="{{ $scored['hours'] === $score['hours'] ? 'true' : 'false' }}"
                    >{{ $scored['hours'] }} h</button>
                @endforeach
            </div>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <div class="min-w-0">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
                    <h3 class="m-0 font-display text-[22px] leading-tight font-semibold sm:text-[24px]">Skill by day</h3>
                    <p class="m-0 flex flex-wrap items-center gap-4 font-mono text-[13px] text-ink-3">
                        <span class="flex items-center gap-2"><span class="swatch {{ \App\Enums\Channel::Temperature->backgroundClass() }}"></span>shown</span>
                        <span class="flex items-center gap-2">{!! $base !!}base</span>
                        @if ($experiment !== null)
                            <span class="flex items-center gap-2"><span class="swatch {{ \App\Enums\Channel::Light->backgroundClass() }}"></span>{{ $experiment['version'] }}</span>
                        @endif
                        <span>{{ $score['hours'] }} h ahead</span>
                    </p>
                </div>
                <div
                    class="rounded-[10px] border border-line bg-screen p-3"
                    data-accuracy-chart="days"
                    data-accuracy-rows="{{ json_encode($score['days']) }}"
                    data-accuracy-experiment="{{ $experiment['version'] ?? '' }}"
                    role="img"
                    aria-label="Temperature {{ $score['hours'] }} h ahead by day: how much smaller the miss was than the naive guess's, shown and base{{ $experiment === null ? '' : ' and '.$experiment['version'] }}"
                >
                    <div wire:ignore data-accuracy-canvas class="h-[260px] w-full"></div>
                </div>
            </div>
            <div class="min-w-0">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
                    <h3 class="m-0 font-display text-[22px] leading-tight font-semibold sm:text-[24px]">Range width</h3>
                    <p class="m-0 font-mono text-[13px] text-ink-3">10-90 %, {{ $score['hours'] }} h ahead, °C</p>
                </div>
                <div
                    class="rounded-[10px] border border-line bg-screen p-3"
                    data-accuracy-chart="widths"
                    data-accuracy-rows="{{ json_encode($score['days']) }}"
                    data-accuracy-experiment="{{ $experiment['version'] ?? '' }}"
                    role="img"
                    aria-label="Width of the {{ $score['hours'] }} h range by day, shown and base{{ $experiment === null ? '' : ' and '.$experiment['version'] }}"
                >
                    <div wire:ignore data-accuracy-canvas class="h-[260px] w-full"></div>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
            <div class="min-w-0">
                <h3 class="m-0 mb-4 font-display text-[22px] leading-tight font-semibold sm:text-[24px]">Rain chance</h3>
                <div class="rounded-[10px] border border-line bg-screen p-6">
                    <p class="label-mono m-0">Mean chance given, {{ $score['hours'] }} h ahead</p>
                    @if ($rain['count'] === 0)
                        <p class="m-0 mt-6 text-[16px] text-ink-2">Not scored yet: the microphone has not listened through a whole forecast.</p>
                    @else
                        @foreach ([
                            ['When it rained', $rain['chanceWhenRain'], 'no rain heard', 'bg-ink'],
                            ['When it did not', $rain['chanceWhenDry'], 'no dry spell', 'bg-ink-3'],
                        ] as [$label, $chance, $none, $bar])
                            <div class="mt-6">
                                <div class="flex items-baseline justify-between gap-3 text-[16px]">
                                    <span class="text-ink-2">{{ $label }}</span>
                                    <span @class(['num font-mono', 'text-[22px] font-medium' => $chance !== null, 'text-[15px] text-ink-3' => $chance === null])>{{ $chance === null ? $none : \App\ValueObject\Figure::format($chance, 0).' %' }}</span>
                                </div>
                                <div class="mt-2 h-2 rounded-full bg-line"><div class="{{ $bar }} h-full rounded-full" style="width: {{ min(100, $chance ?? 0) }}%"></div></div>
                            </div>
                        @endforeach
                    @endif
                    <p class="m-0 mt-6 text-[15px] leading-relaxed text-ink-3">Rain as the INMP441 microphone heard it within the hours ahead. The gap between the two is what matters; drizzle is not heard, and a single station hears rain only once it arrives.</p>
                </div>
            </div>
            <div class="min-w-0" x-data="{ period: 'today' }">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
                    <h3 class="m-0 font-display text-[22px] leading-tight font-semibold sm:text-[24px]" x-text="period === 'today' ? 'Today against the forecast' : 'Bias by hour of day'">Today against the forecast</h3>
                    <div class="seg" role="group" aria-label="Period">
                        @foreach (['today' => 'Today', ...collect(\App\Enums\ScorePeriod::cases())->mapWithKeys(fn (\App\Enums\ScorePeriod $period): array => [$period->value => $period->label()])] as $period => $label)
                            <button
                                type="button"
                                x-on:click="period = '{{ $period }}'; $dispatch('accuracy-period', { period: '{{ $period }}' })"
                                x-bind:aria-pressed="(period === '{{ $period }}').toString()"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <p class="m-0 mb-3 flex flex-wrap items-center gap-4 font-mono text-[13px] text-ink-3">
                    <span class="flex items-center gap-2" x-show="period === 'today'"><span class="swatch bg-ink"></span>measured</span>
                    <span class="flex items-center gap-2"><span class="swatch {{ \App\Enums\Channel::Temperature->backgroundClass() }}"></span>shown</span>
                    <span class="flex items-center gap-2">{!! $base !!}base</span>
                    @if ($experiment !== null)
                        <span class="flex items-center gap-2"><span class="swatch {{ \App\Enums\Channel::Light->backgroundClass() }}"></span>{{ $experiment['version'] }}</span>
                    @endif
                    <span>{{ $score['hours'] }} h ahead, °C</span>
                </p>
                <div
                    class="rounded-[10px] border border-line bg-screen p-3"
                    data-accuracy-chart="hours"
                    data-accuracy-rows="{{ json_encode(['today' => $score['today'] ?? [], ...$score['byHour']]) }}"
                    data-accuracy-experiment="{{ $experiment['version'] ?? '' }}"
                    role="img"
                    aria-label="Temperature {{ $score['hours'] }} h ahead: today's readings against the forecasts for them, or the reading minus the forecast by hour of the day, shown and base{{ $experiment === null ? '' : ' and '.$experiment['version'] }}"
                >
                    <div wire:ignore data-accuracy-canvas class="h-[220px] w-full"></div>
                </div>
                @if ($experiment !== null)
                    <p class="m-0 mt-3 text-[15px] leading-relaxed text-ink-3" x-show="period !== 'today' && ! {{ json_encode($experiment['covers']) }}.includes(period)" x-cloak>{{ $experiment['version'] }} runs since {{ \App\ValueObject\LocalTime::of($experiment['since'])->date() }}, not long enough for this period.</p>
                @endif
                <p class="m-0 mt-3 text-[15px] leading-relaxed text-ink-3" x-show="period === 'today'">Today's readings since midnight against what each forecast said {{ $score['hours'] }} h before; the band is the shown forecast's 10-90 % range.</p>
                <p class="m-0 mt-3 text-[15px] leading-relaxed text-ink-3" x-show="period !== 'today'" x-cloak>Reading minus the forecast's median, by the hour it was for; above zero the balcony read warmer than forecast. After sunrise the sun heats the shield and the reading runs high; the correction learns that from 20-minute solar-time bins.</p>
            </div>
        </div>
    </section>
@endif
