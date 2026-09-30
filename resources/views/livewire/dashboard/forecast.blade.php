{{-- A hairline between the reading now and the hours ahead: beside it on a wide screen, above it once stacked. --}}
<div id="forecast" class="relative flex min-w-0 flex-col border-t border-white/30 pt-6 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-10 dark:border-white/15" aria-label="Forecast">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 class="text-[15px] font-bold">Next six hours</h2>
        <p class="text-[13px] text-white/80">
            made {{ $forecast['at'] }} · {{ $forecast['ago'] }} ·
            {{ $forecast['corrected'] ? 'fitted to this station' : 'not yet fitted to this station' }}
        </p>
    </div>

    @php($curve = $this->forecastCurve)
    @if ($curve !== null)
        {{-- Now, then each hour's median over its range. One column per point, as in the list below;
             both sit right of the same gutter, which holds the y-axis labels. --}}
        <div class="relative mt-4 ml-10 h-28 sm:h-36" data-forecast-curve aria-hidden="true">
            @foreach ($curve['ticks'] as $tick)
                <span
                    class="absolute right-full mr-2 -translate-y-1/2 font-mono text-[11px] whitespace-nowrap text-white/75 tabular-nums"
                    style="top: {{ $tick['y'] }}%"
                    data-forecast-tick
                >{{ number_format($tick['value'], 1, ',', ' ') }}°</span>
            @endforeach
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible">
                @foreach ($curve['ticks'] as $tick)
                    <line x1="0" x2="100" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" class="stroke-white/25" stroke-width="1" stroke-dasharray="2 3" vector-effect="non-scaling-stroke" />
                @endforeach
                <path d="{{ $curve['band'] }}" class="fill-white/20" />
                <path d="{{ $curve['line'] }}" class="fill-none stroke-white" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            </svg>
            @foreach ($curve['points'] as $point)
                <span
                    @class([
                        'absolute size-[9px] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white',
                        'bg-white' => $loop->first,
                        'bg-sky-500 dark:bg-indigo-900' => ! $loop->first,
                    ])
                    style="left: {{ $point['x'] }}%; top: {{ $point['y'] }}%"
                ></span>
            @endforeach
        </div>
    @endif

    {{-- As many columns as the curve has points, so each label sits under its own. --}}
    <ol @class(['mt-2 grid text-center', 'ml-10' => $curve !== null]) style="grid-template-columns: repeat({{ count($forecast['horizons']) + 1 }}, minmax(0, 1fr))">
        <li class="flex flex-col items-center gap-1 px-0.5">
            <span class="text-xs font-bold">now</span>
            <span class="font-mono text-[11px] text-white/75 tabular-nums">{{ $this->lastMeasurement?->clock() }}</span>
            @if ($temperature !== null)
                <span class="mt-7 text-[13px] font-extrabold tabular-nums sm:text-[15px]">{{ number_format($temperature['now'], 1, ',', ' ') }}°</span>
            @endif
        </li>
        @foreach ($forecast['horizons'] as $hour)
            <li class="flex min-w-0 flex-col items-center gap-1 px-0.5">
                <span class="text-xs font-bold">+{{ $hour['hours'] }} h</span>
                <span class="font-mono text-[11px] text-white/75 tabular-nums">{{ $hour['clock'] }}</span>
                <flux:icon :icon="$hour['sky']['icon']" class="size-6" title="{{ ucfirst($hour['sky']['label']) }}" aria-hidden="true" />
                <span class="sr-only">{{ ucfirst($hour['sky']['label']) }}.</span>
                {{-- A phone gives each hour about 40 px: smaller figures, the trend arrow and the rain's umbrella stacked. --}}
                <span class="flex flex-col items-center gap-0.5 text-[13px] font-extrabold tabular-nums sm:flex-row sm:text-[15px]">
                    {{ number_format($hour['t'], 1, ',', ' ') }}°
                    <flux:icon
                        :icon="match ($hour['trend']) { 'rising' => 'arrow-trending-up', 'falling' => 'arrow-trending-down', default => 'minus' }"
                        variant="micro"
                        class="text-white/80"
                        title="{{ ucfirst($hour['trend']) }}"
                        aria-hidden="true"
                    />
                    <span class="sr-only">{{ ucfirst($hour['trend']) }}.</span>
                </span>
                <span class="hidden font-mono text-[10.5px] leading-tight text-white/80 tabular-nums sm:block">{{ number_format($hour['tLow'], 1, ',', ' ') }} to {{ number_format($hour['tHigh'], 1, ',', ' ') }}</span>
                <span class="inline-flex flex-col items-center gap-0.5 font-mono text-[11px] whitespace-nowrap text-white/85 tabular-nums sm:flex-row">
                    <flux:icon.umbrella variant="micro" class="size-3" aria-hidden="true" />
                    <span class="sr-only">rain</span>
                    {{ $hour['rain'] }} %
                </span>
            </li>
        @endforeach
    </ol>

    {{-- CC BY 4.0 asks for the attribution. --}}
    <p class="mt-4 text-[12.5px] leading-snug text-white/80">
        Range: eight readings in ten land inside it. Rain: the chance of at least 0.1 mm by then.
        Trained on ČHMÚ station records (CC BY 4.0), run from this station's readings alone.
    </p>
</div>
