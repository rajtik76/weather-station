@php($temperature = $this->metrics['t'] ?? null)
@php($celsius = \App\Enums\Channel::Temperature)
@include('livewire.overview.header')

<x-horizon.today />

@unless (app()->isProduction())
    <x-horizon.preview-controls />
@endunless

<div class="hz-copy hz-gutter">
    <span hidden data-rain-state="{{ $this->rainIntensity?->value ?? 'none' }}" data-wind-state="{{ $this->windIntensity?->value ?? 'calm' }}"></span>
    @if ($temperature === null)
        <p class="hz-kicker">Waiting for the first reading.</p>
    @else
        @php($shadeNow = $this->temperatureTrace->latest())
        <p class="hz-kicker">{{ $shadeNow === null ? 'In the shield, right now' : 'In the shade, right now' }}</p>
        <h1 class="hz-temp"><span class="sr-only">{{ $shadeNow === null ? 'Sensor' : 'Shade' }} temperature in Plzeň: </span>{{ $celsius->format($shadeNow ?? $temperature['now']) }}<span class="hz-temp-unit">{{ $celsius->unit() }}</span></h1>
        <p class="hz-sensor">
            <span>Sensor in the shield <b>{{ $celsius->format($temperature['now']) }} {{ $celsius->unit() }}</b></span>
            @if ($shadeNow !== null)
                <span title="Shield correction">Shield correction <b>{{ $celsius->format($shadeNow - $temperature['now'], signed: true) }} {{ $celsius->unit() }}</b></span>
            @else
                <span title="A valid calibration and light history are required.">Shade estimate unavailable for this reading.</span>
            @endif
            <span>
                @if (abs($temperature['delta']) < 0.05)
                    Sensor steady.
                @else
                    Sensor {{ $temperature['delta'] > 0 ? 'rising' : 'falling' }} <b>{{ $celsius->format(abs($temperature['delta'])) }} {{ $celsius->unit() }}</b> an hour.
                @endif
            </span>
        </p>
    @endif
    <ul class="hz-chips">
        @if ($this->rainNow !== null)
            <x-horizon.chip icon="rain" data-rain-now>Raining now, {{ $this->rainNow->span() }} · {{ $this->rainIntensity?->value }}</x-horizon.chip>
        @endif
        @if ($this->windIntensity !== null)
            <x-horizon.chip icon="wind" data-wind-now>{{ $this->windIntensity === \App\Enums\WindIntensity::Strong ? 'Strong wind now' : 'Windy now' }}</x-horizon.chip>
        @endif
        @unless (app()->isProduction())
            <x-horizon.chip icon="rain" data-preview-rain wire:ignore hidden></x-horizon.chip>
            <x-horizon.chip icon="wind" data-preview-wind wire:ignore hidden></x-horizon.chip>
        @endunless
    </ul>
</div>

<x-horizon.day-bar />
