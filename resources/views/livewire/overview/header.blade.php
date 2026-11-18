@php($page ??= 'overview')
{{-- The menu folds behind a button on phones. Alpine owns the open state; wire:ignore.self keeps a poll's morph off its attributes. --}}
<div class="hz-nav hz-gutter" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <a href="{{ route('overview', $this->sensorQuery()) }}" class="hz-brand" aria-label="Balcony Station, overview"><b>Balcony Station</b><span>Plzeň</span></a>
    <button
        type="button"
        wire:ignore.self
        class="hz-burger hz-glass"
        x-on:click="open = ! open"
        aria-controls="site-menu"
        aria-expanded="false"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-label="Menu"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path x-show="! open" d="M4 7h16M4 12h16M4 17h16" />
            <path x-show="open" x-cloak d="M6 6l12 12M18 6L6 18" />
        </svg>
    </button>
    <nav id="site-menu" aria-label="Main" class="hz-menu" wire:ignore.self x-bind:class="{ 'is-open': open }">
        <ul class="hz-links">
            <li><a href="{{ route('overview', $this->sensorQuery()) }}" @if ($page === 'overview') aria-current="page" @endif>Overview</a></li>
            <li><a href="{{ route('charts', $this->sensorQuery()) }}" @if ($page === 'charts') aria-current="page" @endif>Charts</a></li>
            <li><a href="{{ route('comparison', $this->sensorQuery()) }}" @if ($page === 'comparison') aria-current="page" @endif>Reference</a></li>
        </ul>
        @if ($this->hasSensorChoice)
            <label class="hz-sensor-pick">
                <span>Sensor</span>
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
    </nav>
</div>
