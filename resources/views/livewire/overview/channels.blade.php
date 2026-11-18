@php($notes = [
    'h' => 'SHT4x, relative humidity.',
    'p' => 'BMP280 indoors, reduced to sea level.',
    'n' => match ($this->rainHeard) {
        true => 'INMP441 in the shield. Rain heard now.',
        false => 'INMP441 in the shield. No rain heard.',
        null => 'INMP441 in the shield.',
    },
    'l' => 'VEML7700 in the shield, behind the louvers.',
])
@php($channels = array_values(array_filter($this->channels, fn (\App\Enums\Channel $channel): bool => $channel !== \App\Enums\Channel::Temperature)))

@if ($channels !== [] || $this->dewPoint !== null)
    <x-horizon.section aria-labelledby="readings-h">
        <x-horizon.heading id="readings-h">The rest of the balcony</x-horizon.heading>
        <p class="hz-lede">The last 24 hours in ten-minute windows, with the lowest and highest sample of the day.</p>
        <dl class="hz-readings">
            @foreach ($channels as $channel)
                @php($figures = $this->metrics[$channel->value])
                @php($trace = \App\ValueObject\Trace::spanning($channel->isLogarithmic() ? array_map(fn (float $value): float => log10(max($value, 0.01)), $figures['trace']) : $figures['trace']))
                @php($silent = in_array($channel, $this->silentChannels, true))
                <x-horizon.reading :label="$channel->label()" :value="$channel->format($figures['now'])" :unit="$channel->unit()" :trace="$trace->line()" :silent="$silent">
                    min {{ $channel->format($figures['dayMin']) }} · max {{ $channel->format($figures['dayMax']) }} · Δ/h {{ $channel->format($figures['delta'], signed: true) }}<br>
                    @if ($silent)
                        Missing from the newest window: the figure is the last one read.
                    @endif
                    {{ $notes[$channel->value] }}
                </x-horizon.reading>
            @endforeach
            @if ($this->dewPoint !== null)
                <x-horizon.reading label="Dew point" :value="\App\Enums\Channel::Temperature->format($this->dewPoint)" :unit="\App\Enums\Channel::Temperature->unit()">
                    From the SHT4x temperature and humidity in the shield.
                </x-horizon.reading>
            @endif
        </dl>
    </x-horizon.section>
@endif
