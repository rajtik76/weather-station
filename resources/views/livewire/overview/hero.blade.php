@php($temperature = $this->metrics['t'] ?? null)
@php($temperatureChannel = \App\Enums\Channel::Temperature)
<section class="page-wrap pt-10 sm:pt-14" aria-labelledby="now-h">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
        <h1 id="now-h" class="m-0 font-display text-[30px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[38px]">The balcony, right now</h1>
        @if ($this->measuredAt !== null)
            <p
                wire:key="newest-{{ $this->newestMeasurement->timestamp }}"
                @class(['arrival num m-0 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 rounded-full border border-line-2 bg-screen px-3.5 py-1.5 font-mono text-[14px]', 'just-arrived' => $readingArrived])
                title="Newest reading from the station"
            >
                <span @class(['electron', 'text-ch2' => ! $this->isSilent, 'text-ink-3' => $this->isSilent]) aria-hidden="true"></span>
                <span class="font-medium text-ink">{{ $this->isSilent ? 'STOP' : 'RUN' }}</span>
                <span class="text-ink-2">{{ $this->measuredAgo }}</span>
                <span class="text-ink-3">{{ $this->measuredAt }}</span>
            </p>
        @endif
    </div>

    @if ($temperature === null)
        <p class="m-0 rounded-[10px] border border-line bg-screen px-6 py-10 text-ink-2">Waiting for the first reading.</p>
    @else
        @php($trace = \App\ValueObject\Trace::spanning($temperature['trace']))
        @php($newest = $trace->points[array_key_last($trace->points)])
        @php($traceLow = min($temperature['trace']))
        @php($traceHigh = max($temperature['trace']))
        @php($ticks = \App\ValueObject\Trace::ticks($traceLow, $traceHigh, fn (float $value): float => \App\ValueObject\Trace::levelOf($value, $traceLow, $traceHigh), [0.1, 0.2, 0.5, 1.0, 2.0, 5.0, 10.0]))
        <div class="overflow-hidden rounded-[10px] border border-line bg-screen lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:grid-rows-[1fr_auto]">
            <figure class="m-0 min-w-0 border-b border-line lg:col-start-1 lg:row-start-1 lg:border-r lg:border-b-0">
                <figcaption class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 pt-4 font-mono text-[13px] text-ink-3 sm:px-6">
                    <span class="flex items-center gap-2"><span class="swatch {{ $temperatureChannel->backgroundClass() }}"></span><span class="text-ink">{{ $temperatureChannel->code() }}</span><span>Temperature, last 24 h</span></span>
                    <span class="num">min {{ $temperatureChannel->format($temperature['dayMin']) }} · max {{ $temperatureChannel->format($temperature['dayMax']) }} {{ $temperatureChannel->unit() }}</span>
                </figcaption>
                <div class="relative ml-[calc(1.5rem+52px)] mr-6 mt-4 mb-3 h-[240px] sm:ml-[calc(1.75rem+52px)] sm:mr-7 sm:h-[340px]">
                    <svg class="graticule absolute inset-0 h-full w-full" viewBox="0 0 100 80" preserveAspectRatio="none" aria-hidden="true">
                        @foreach (range(0, 100, 10) as $x)
                            <line x1="{{ $x }}" y1="0" x2="{{ $x }}" y2="80" style="stroke: var({{ in_array($x, [0, 50, 100], true) ? '--grid-2' : '--grid' }})" />
                        @endforeach
                        @foreach (range(0, 80, 10) as $y)
                            <line x1="0" y1="{{ $y }}" x2="100" y2="{{ $y }}" style="stroke: var({{ in_array($y, [0, 40, 80], true) ? '--grid-2' : '--grid' }})" />
                        @endforeach
                        @foreach (range(2, 98, 2) as $tick)
                            <line x1="{{ $tick }}" y1="39.3" x2="{{ $tick }}" y2="40.7" style="stroke: var(--grid-2)" />
                        @endforeach
                        @foreach (range(2, 78, 2) as $tick)
                            <line x1="49.5" y1="{{ $tick }}" x2="50.5" y2="{{ $tick }}" style="stroke: var(--grid-2)" />
                        @endforeach
                    </svg>
                    <div class="absolute inset-0" role="img" aria-label="Temperature over the last 24 hours, between {{ $temperatureChannel->format($temperature['dayMin']) }} and {{ $temperatureChannel->format($temperature['dayMax']) }} {{ $temperatureChannel->unit() }}, now {{ $temperatureChannel->format($temperature['now']) }} {{ $temperatureChannel->unit() }}.">
                        <svg class="layer phosphor" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" style="--trace: {{ $temperatureChannel->cssColour() }}">
                            <path d="{{ $trace->line() }}" class="{{ $temperatureChannel->strokeClass() }}" style="fill: none; stroke-width: 2px; stroke-linejoin: round; stroke-linecap: round" />
                        </svg>
                        @foreach ($ticks as $tick)
                            <span class="ylab" style="top: {{ $tick['y'] }}%">{{ \App\ValueObject\Figure::format($tick['value'], 1) }} {{ $temperatureChannel->unit() }}</span>
                        @endforeach
                        <span class="trig {{ $temperatureChannel->textClass() }}" style="left: {{ $newest['x'] }}%; top: {{ $newest['y'] }}%" aria-hidden="true"></span>
                    </div>
                </div>
                <div class="num flex justify-between gap-4 pr-5 pb-4 pl-[calc(1.5rem+52px)] font-mono text-[13px] text-ink-3 sm:pr-6 sm:pl-[calc(1.75rem+52px)]">
                    <span>24 h ago</span>
                    <span class="text-ink-2">now</span>
                </div>
            </figure>

            <aside class="flex flex-col p-5 sm:p-6 lg:col-start-2 lg:row-span-2 lg:row-start-1" aria-label="Channel 1 measurements">
                <p class="label-mono m-0">Measure · {{ $temperatureChannel->code() }}</p>
                <p class="num m-0 mt-4 font-mono text-[60px] leading-none font-medium tracking-[-0.03em]">{{ $temperatureChannel->format($temperature['now']) }}<span class="ml-1.5 align-top text-[22px] font-normal tracking-normal text-ink-3">{{ $temperatureChannel->unit() }}</span></p>
                <p class="m-0 mt-3 text-[16px] text-ink-2">
                    Outside air,
                    @if (abs($temperature['delta']) < 0.05)
                        steady.
                    @else
                        {{ $temperature['delta'] > 0 ? 'rising' : 'falling' }} <span class="num font-mono">{{ $temperatureChannel->format(abs($temperature['delta'])) }} {{ $temperatureChannel->unit() }}</span> an hour.
                    @endif
                </p>
                <dl class="num m-0 mt-6 font-mono text-[15px]">
                    <div class="grid grid-cols-[88px_1fr] items-baseline border-t border-line py-2.5">
                        <dt class="label-mono">Min</dt><dd class="m-0">{{ $temperatureChannel->format($temperature['dayMin']) }} {{ $temperatureChannel->unit() }}</dd>
                    </div>
                    <div class="grid grid-cols-[88px_1fr] items-baseline border-t border-line py-2.5">
                        <dt class="label-mono">Max</dt><dd class="m-0">{{ $temperatureChannel->format($temperature['dayMax']) }} {{ $temperatureChannel->unit() }}</dd>
                    </div>
                    <div class="grid grid-cols-[88px_1fr] items-baseline border-t border-line py-2.5">
                        <dt class="label-mono">Δ / h</dt><dd class="m-0">{{ $temperatureChannel->format($temperature['delta'], signed: true) }} {{ $temperatureChannel->unit() }}</dd>
                    </div>
                    @if ($this->dewPoint !== null)
                        <div class="grid grid-cols-[88px_1fr] items-baseline border-y border-line py-2.5">
                            <dt class="label-mono">Dew pt</dt><dd class="m-0">{{ $temperatureChannel->format($this->dewPoint) }} {{ $temperatureChannel->unit() }}</dd>
                        </div>
                    @endif
                </dl>
                <p class="m-0 mt-auto pt-6 text-[15px] leading-relaxed text-ink-3">On a clear morning the sun hits the shield and the reading runs high for an hour or two. It stays in the record on purpose.</p>
            </aside>
            @include('livewire.overview.hero-readouts')
        </div>
    @endif
</section>
