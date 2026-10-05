@php($readouts = array_values(array_filter($this->channels, fn (\App\Enums\Channel $channel): bool => $channel !== \App\Enums\Channel::Temperature)))
@if ($readouts !== [])
    <div class="@container min-w-0 border-t border-line lg:col-start-1 lg:row-start-2 lg:border-r">
        <dl @class([
            'num m-0 grid gap-px bg-line font-mono',
            'grid-cols-2 @3xl:grid-cols-4' => count($readouts) === 4,
            'grid-cols-1 @md:grid-cols-3' => count($readouts) === 3,
            'grid-cols-2' => count($readouts) <= 2,
        ])>
            @foreach ($readouts as $channel)
                @php($figures = $this->metrics[$channel->value])
                @php($silent = in_array($channel, $this->silentChannels, true))
                <div class="bg-screen px-3 py-3.5 sm:px-5">
                    <dt class="sr-only">{{ $channel->label() }}</dt>
                    <dd class="m-0">
                        <p class="m-0 flex items-center gap-2">
                            <span class="swatch shrink-0 {{ $channel->backgroundClass() }}" aria-hidden="true"></span>
                            <span @class(['text-[length:min(20px,calc(10.8cqi_-_16px))] leading-none font-medium whitespace-nowrap tracking-[-0.02em]', 'text-ink-3' => $silent]) @if ($silent) title="Missing from the newest window: the figure is the last one read." @endif>{{ $channel->format($figures['now']) }}<span class="ml-1 text-[13px] font-normal tracking-normal text-ink-3">{{ $channel->unit() }}</span>@if ($silent)<span class="sr-only">, last read, missing from the newest window</span>@endif</span>
                        </p>
                        <dl class="m-0 mt-2 grid grid-cols-[auto_1fr] gap-x-2 pl-6 text-[12.5px] leading-snug">
                            <dt class="text-ink-3">min</dt><dd class="m-0 whitespace-nowrap text-ink-2">{{ $channel->format($figures['dayMin']) }}</dd>
                            <dt class="text-ink-3">max</dt><dd class="m-0 whitespace-nowrap text-ink-2">{{ $channel->format($figures['dayMax']) }}</dd>
                        </dl>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
@endif
