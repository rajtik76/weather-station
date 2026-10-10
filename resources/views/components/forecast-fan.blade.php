@props([
    'chart',
    'horizons',
    'showModel' => false,
])

@php($temperatureChannel = \App\Enums\Channel::Temperature)
@php($drop = '<svg class="shrink-0 text-ch2" width="10" height="13" viewBox="0 0 10 13" aria-hidden="true"><path d="M5 .8C5 .8 1 5.8 1 8.6a4 4 0 0 0 8 0C9 5.8 5 .8 5 .8Z" fill="currentColor" /></svg>')
<div {{ $attributes->class('flex flex-1 flex-col rounded-[10px] border border-line bg-screen') }}>
    <div class="relative min-h-[270px] flex-1 sm:min-h-[300px]" role="img" aria-label="Temperature over the last six hours and the forecast for the next six, with its range.">
        <div class="plot" style="top: 30px; right: 28px; bottom: 40px; left: 64px">
            <svg class="layer graticule" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                @foreach ($chart->hours as $hour)
                    <line x1="{{ $hour['x'] }}" y1="0" x2="{{ $hour['x'] }}" y2="100" style="stroke: var(--grid)" />
                @endforeach
                @foreach ($chart->ticks as $tick)
                    <line x1="0" y1="{{ $tick['y'] }}" x2="100" y2="{{ $tick['y'] }}" style="stroke: var(--grid)" />
                @endforeach
                <line x1="0" y1="0" x2="0" y2="100" style="stroke: var(--grid-2)" />
                <line x1="0" y1="100" x2="100" y2="100" style="stroke: var(--grid-2)" />
                <line x1="{{ $chart->now()['x'] }}" y1="0" x2="{{ $chart->now()['x'] }}" y2="100" style="stroke: var(--grid-2)" />
            </svg>
            <svg class="layer" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <path d="{{ $chart->band() }}" class="{{ $temperatureChannel->bandFillClass() }}" style="stroke: none" />
                <path d="{{ $chart->medianLine() }}" class="{{ $temperatureChannel->strokeClass() }}" style="fill: none; stroke-width: 2px; stroke-dasharray: 6 4; stroke-linejoin: round; stroke-linecap: round" />
            </svg>
            <svg class="layer phosphor" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" style="--trace: {{ $temperatureChannel->cssColour() }}">
                <path d="{{ $chart->measuredLine() }}" class="{{ $temperatureChannel->strokeClass() }}" style="fill: none; stroke-width: 2px; stroke-linejoin: round; stroke-linecap: round" />
            </svg>
            @foreach ($chart->ticks as $tick)
                <span class="ylab" style="top: {{ $tick['y'] }}%">{{ \App\ValueObject\Figure::format($tick['value'], 0) }} °C</span>
            @endforeach
            <span class="xlab" style="left: 0">−6 h</span>
            @foreach ($chart->hours as $hour)
                <span class="pt" style="left: {{ $hour['x'] }}%; top: {{ $hour['y'] }}%"></span>
                @if ($loop->iteration % 2 === 0)
                    <span class="xlab" style="left: {{ $hour['x'] }}%">{{ $hour['clock'] }}</span>
                @endif
            @endforeach
            <span class="trig {{ $temperatureChannel->textClass() }}" style="left: {{ $chart->now()['x'] }}%; top: {{ $chart->now()['y'] }}%" aria-hidden="true"></span>
            <span class="tag" style="top: 0; left: {{ $chart->now()['x'] }}%; transform: translate(-50%, -125%)">now</span>
        </div>
    </div>
    <ol class="m-0 grid list-none grid-cols-3 border-t border-line p-0 sm:grid-cols-6">
        @foreach ($horizons as $hour)
            <li @class([
                'border-line px-4 py-3.5',
                'border-r' => $loop->iteration % 3 !== 0,
                'sm:border-r' => ! $loop->last,
                'border-b sm:border-b-0' => $loop->iteration <= 3,
            ])>
                <p class="m-0 font-mono text-[13px] text-ink-3">{{ $hour->clock }}</p>
                <p class="num m-0 mt-1 font-mono text-[20px] leading-tight font-medium">{{ \App\ValueObject\Figure::format($hour->t, 1) }}<span class="text-[14px] font-normal text-ink-3"> °C</span></p>
                <p class="num m-0 font-mono text-[13px] text-ink-3">{{ \App\ValueObject\Figure::range($hour->tLow, $hour->tHigh, 1) }}</p>
                @if ($showModel && $hour->model !== null)
                    <p class="m-0 mt-1 font-mono text-[12px] text-ink-3">by <span class="text-ink-2">{{ $hour->model }}</span></p>
                @endif
                <div class="mt-3">
                    <p class="num m-0 flex items-center gap-1.5 font-mono text-[13px] text-ink-2">{!! $drop !!}<span class="sr-only">rain chance </span>{{ $hour->rain }} %<span class="text-ink-3">rain</span></p>
                    <span class="mt-1.5 block h-1 rounded-full bg-line"><span class="block h-full rounded-full bg-ch2" style="width: {{ min(100, $hour->rain) }}%"></span></span>
                </div>
            </li>
        @endforeach
    </ol>
</div>
