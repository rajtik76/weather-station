{{-- The dew point is derived, so it starts off. --}}
@php($hasNoise = $this->noise !== [])
@php($weatherChannels = [
    ...array_map(fn (\App\Enums\Channel $channel): array => [
        'key' => $channel->value,
        'ch' => $channel->code(),
        'colour' => $channel->backgroundClass(),
        'label' => $channel->label().', '.$channel->unit(),
    ], [\App\Enums\Channel::Temperature, \App\Enums\Channel::Humidity]),
    ['key' => 'd', 'ch' => 'REF', 'colour' => 'bg-ref', 'label' => 'Dew point, °C'],
])

{{-- station-charts.js watches these attributes; Livewire never touches the canvases. --}}
<div
    data-chart-rows="{{ json_encode($this->readings) }}"
    data-navigator-rows="{{ json_encode($this->overview) }}"
    data-chart-events="{{ json_encode($this->stationEvents) }}"
    data-hidden-channels="{{ json_encode($this->hiddenChannels) }}"
    data-noise-rows="{{ json_encode($this->noise) }}"
    data-noise-rain="{{ json_encode($this->rainSlots) }}"
    data-light-rows="{{ json_encode($this->light) }}"
    data-light-scale="{{ $this->lightAxis->name }}"
    data-window-from="{{ $this->windowMs['from'] }}"
    data-window-to="{{ $this->windowMs['to'] }}"
    data-chart-component="{{ $this->getId() }}"
    hidden
></div>

<section class="page-wrap mt-8" aria-label="Channels">
    <div class="rounded-[10px] border border-line bg-screen px-3 pt-5 pb-4 sm:px-5">
        {{-- Above the strips: below them a drag moves an off-screen chart. --}}
        <div class="border-b border-line pb-5">
            <p class="label-mono m-0 mb-2">Whole record</p>
            <div
                wire:ignore
                data-navigator
                class="h-20 w-full"
                role="img"
                aria-label="The whole record, with the shown window marked. Drag its edges to move through time."
            ></div>
        </div>

        @unless ($this->hasReadings)
            <div class="py-12 text-center">
                <p class="m-0 font-display text-[19px] font-semibold">Nothing in this range</p>
                <p class="m-0 mx-auto mt-3 max-w-sm text-[15px] text-ink-2">
                    No reading was recorded between {{ $this->window['from'] }} and
                    {{ $this->window['to'] }}. Pick a wider range, or reset the zoom.
                </p>
            </div>
        @endunless

        <div class="mt-5 space-y-8">
            <x-channel-strip key="th" label="Temperature and humidity" :channel="\App\Enums\Channel::Temperature->code().' · '.\App\Enums\Channel::Humidity->code()" height="h-72 sm:h-80">
                @foreach ($weatherChannels as $channel)
                    @php($shown = $this->channels[$channel['key']] ?? false)
                    @php($last = $this->isLastChannel($channel['key']))
                    <button
                        type="button"
                        wire:click="toggleChannel('{{ $channel['key'] }}')"
                        aria-pressed="{{ $shown ? 'true' : 'false' }}"
                        @disabled($last)
                        @class([
                            'flex items-center gap-2 rounded-md px-1.5 py-1',
                            'text-ink-2' => $shown,
                            'text-ink-3 line-through decoration-line-2' => ! $shown,
                            'cursor-pointer hover:text-ink' => ! $last,
                            'cursor-default' => $last,
                        ])
                    >
                        <span @class(['swatch', $channel['colour'], 'opacity-35' => ! $shown]) aria-hidden="true"></span>
                        <span>{{ $channel['ch'] }}</span>
                        <span>{{ $channel['label'] }}</span>
                    </button>
                @endforeach
            </x-channel-strip>

            <x-channel-strip key="p" :label="\App\Enums\Channel::Pressure->label()" :channel="\App\Enums\Channel::Pressure->code()" height="h-48 sm:h-56">
                <span class="flex items-center gap-2 text-ink-2"><span class="swatch {{ \App\Enums\Channel::Pressure->backgroundClass() }}" aria-hidden="true"></span>{{ \App\Enums\Channel::Pressure->label() }}, {{ \App\Enums\Channel::Pressure->unit() }}</span>
            </x-channel-strip>

            {{-- Protocol 3 only: no strips for a sensor that never sent noise. --}}
            @if ($hasNoise)
                <x-channel-strip key="noise" label="Noise" :channel="\App\Enums\Channel::Noise->code()" height="h-48 sm:h-56">
                    <span class="flex items-center gap-2 text-ink-2"><span class="swatch {{ \App\Enums\Channel::Noise->backgroundClass() }}" aria-hidden="true"></span>LAeq, {{ \App\Enums\Channel::Noise->unit() }}</span>
                    <span class="flex items-center gap-2 text-ink-3"><span class="inline-block h-2.5 w-4 rounded-[2px] {{ \App\Enums\Channel::Noise->bandBackgroundClass() }}" aria-hidden="true"></span>LA90 to LA10</span>
                    <span class="flex items-center gap-2 text-ink-3"><span class="swatch {{ \App\Enums\Channel::Noise->dimBackgroundClass() }}" aria-hidden="true"></span>LAmax</span>
                </x-channel-strip>

                <x-channel-strip key="spectrum" label="Noise spectrum" channel="FFT" height="h-72 sm:h-80">
                    <span class="text-ink-2">Third-octave spectrum, 25 Hz to 8 kHz</span>
                    <span class="flex items-center gap-2 text-ink-3">
                        <span data-spectrum-low wire:ignore></span>
                        <span data-spectrum-scale wire:ignore class="h-2 w-24 rounded-full" aria-hidden="true"></span>
                        <span data-spectrum-high wire:ignore></span>
                    </span>
                </x-channel-strip>
            @endif

            {{-- Protocol 4 only. The VEML7700 sits behind the shield's louvers: lux are relative, read the shape. --}}
            @if ($this->light !== [])
                <x-channel-strip key="light" :label="\App\Enums\Channel::Light->label()" :channel="\App\Enums\Channel::Light->code()" height="h-48 sm:h-56">
                    <span class="flex items-center gap-2 text-ink-2"><span class="swatch {{ \App\Enums\Channel::Light->backgroundClass() }}" aria-hidden="true"></span>Light in the shield, {{ \App\Enums\Channel::Light->unit() }}</span>
                    <span class="flex items-center gap-1" role="group" aria-label="Light scale">
                        @foreach (\App\ValueObject\LightScale::LABELS as $scale => $scaleLabel)
                            @php($chosen = $this->lightAxis->is($scale))
                            <button
                                type="button"
                                wire:click="useLightScale('{{ $scale }}')"
                                aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                @class([
                                    'rounded-md px-1.5 py-1',
                                    'text-ink cursor-default' => $chosen,
                                    'text-ink-3 cursor-pointer hover:text-ink' => ! $chosen,
                                ])
                            >{{ $scaleLabel }}</button>
                        @endforeach
                    </span>
                </x-channel-strip>
            @endif
        </div>
    </div>
</section>
