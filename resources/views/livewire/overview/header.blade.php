@php($page ??= 'overview')
<div class="hz-nav hz-gutter">
    <a href="{{ route('overview', $this->sensorQuery()) }}" class="hz-brand" aria-label="Balcony Station, overview"><b>Balcony Station</b><span>Plzeň</span></a>
    <nav aria-label="Main">
        <ul class="hz-links">
            <li><a href="{{ route('overview', $this->sensorQuery()) }}" @if ($page === 'overview') aria-current="page" @endif>Overview</a></li>
            <li><a href="{{ route('charts', $this->sensorQuery()) }}" @if ($page === 'charts') aria-current="page" @endif>Charts</a></li>
            <li><a href="{{ route('comparison', $this->sensorQuery()) }}" @if ($page === 'comparison') aria-current="page" @endif>Reference</a></li>
        </ul>
    </nav>
    @if ($this->hasSensorChoice)
        <label class="flex items-center gap-2 text-[14px] text-ink-2">
            <span class="hidden sm:inline">Sensor</span>
            <select wire:model.live="sensor" class="hz-select cursor-pointer" aria-label="Choose a sensor">
                @foreach ($this->sensors as $option)
                    <option value="{{ $option->slug }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    @if ($this->measuredAt !== null)
        <p
            wire:key="newest-{{ $this->newestMeasurement->timestamp }}"
            @class(['hz-live hz-glass arrival', 'just-arrived' => $readingArrived ?? false])
            @if ($this->isSilent) data-silent @endif
            title="Newest reading from the station"
        >
            <i aria-hidden="true"></i>
            <span>{{ $this->isSilent ? 'Silent' : 'Live' }}, {{ $this->measuredAgo }}</span>
            <small>{{ $this->measuredAt }}</small>
        </p>
    @endif
</div>
