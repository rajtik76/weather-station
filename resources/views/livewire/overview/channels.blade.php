@php($notes = [
    't' => 'SHT4x, in the shield.'.($this->dewPoint === null ? '' : ' Dew point '.\App\Enums\Channel::Temperature->format($this->dewPoint).' '.\App\Enums\Channel::Temperature->unit().'.'),
    'h' => 'SHT4x, relative humidity.',
    'p' => 'BMP280 indoors, reduced to sea level.',
    'n' => match ($this->rainHeard) {
        true => 'INMP441 in the shield. Rain heard now.',
        false => 'INMP441 in the shield. No rain heard.',
        null => 'INMP441 in the shield.',
    },
    'l' => 'VEML7700 in the shield, behind the louvers.',
])
@php($channels = $this->channels)

@if ($channels !== [])
    <section class="page-wrap mt-20 sm:mt-24" aria-labelledby="ch-h">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-1">
            <h2 id="ch-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Channels</h2>
            <p class="m-0 font-mono text-[14px] text-ink-3">last 24 h · 10 min windows · min / max over 24 h</p>
        </div>

        <div @class([
            'grid grid-cols-1 gap-px overflow-hidden rounded-[10px] border border-line bg-line sm:grid-cols-2',
            'lg:grid-cols-5' => count($channels) === 5,
            'lg:grid-cols-4' => count($channels) === 4,
            'lg:grid-cols-3' => count($channels) === 3,
        ])>
            @foreach ($channels as $channel)
                @php($figures = $this->metrics[$channel->value])
                @php($trace = \App\ValueObject\Trace::spanning($channel->isLogarithmic() ? array_map(fn (float $value): float => log10(max($value, 0.01)), $figures['trace']) : $figures['trace']))
                @php($newest = $trace->points[array_key_last($trace->points)])
                @php($silent = in_array($channel, $this->silentChannels, true))
                <article class="@container flex min-w-0 flex-col bg-screen p-5">
                    <div class="flex items-center justify-between">
                        <h3 class="m-0 flex items-center gap-2.5 text-[16px] font-medium text-ink-2"><span class="swatch {{ $channel->backgroundClass() }}"></span>{{ $channel->label() }}</h3>
                        <span class="font-mono text-[13px] text-ink-3">{{ $channel->code() }}</span>
                    </div>
                    <p @class(['num m-0 mt-4 font-mono text-[length:min(36px,calc(21cqi_-_8px))] leading-none whitespace-nowrap font-medium tracking-[-0.02em]', 'text-ink-3' => $silent])>{{ $channel->format($figures['now']) }}<span class="ml-1 text-[16px] font-normal tracking-normal text-ink-3">{{ $channel->unit() }}</span></p>
                    <div class="relative mt-5 h-10" aria-hidden="true">
                        <svg class="layer" viewBox="0 0 100 100" preserveAspectRatio="none">
                            <path d="{{ $trace->line() }}" class="{{ $channel->strokeClass() }}" style="fill: none; stroke-width: 1.6px; stroke-linejoin: round; stroke-linecap: round" />
                        </svg>
                        @unless ($silent)
                            <span class="absolute h-[7px] w-[7px] rounded-full {{ $channel->backgroundClass() }}" style="right: -3.5px; top: calc({{ $newest['y'] }}% - 3.5px)"></span>
                        @endunless
                    </div>
                    <dl class="num m-0 mt-4 grid grid-cols-3 gap-2 font-mono text-[14px] @max-[14rem]:grid-cols-1 @max-[14rem]:gap-1">
                        <div class="@max-[14rem]:flex @max-[14rem]:items-baseline @max-[14rem]:justify-between"><dt class="text-[12.5px] text-ink-3">min</dt><dd class="m-0 whitespace-nowrap">{{ $channel->format($figures['dayMin']) }}</dd></div>
                        <div class="@max-[14rem]:flex @max-[14rem]:items-baseline @max-[14rem]:justify-between"><dt class="text-[12.5px] text-ink-3">max</dt><dd class="m-0 whitespace-nowrap">{{ $channel->format($figures['dayMax']) }}</dd></div>
                        <div class="@max-[14rem]:flex @max-[14rem]:items-baseline @max-[14rem]:justify-between"><dt class="text-[12.5px] text-ink-3">Δ/h</dt><dd class="m-0 whitespace-nowrap">{{ $channel->format($figures['delta'], signed: true) }}</dd></div>
                    </dl>
                    <p class="m-0 mt-4 text-[14px] leading-snug text-ink-3">
                        @if ($silent)
                            Missing from the newest window: the figure is the last one read.
                        @endif
                        {{ $notes[$channel->value] }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
