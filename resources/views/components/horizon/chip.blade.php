{{-- Lucide glyphs: cloud-rain for rain, wind for wind. --}}
@props(['icon'])

<li {{ $attributes->class('hz-chip hz-glass') }}>
    <svg viewBox="0 0 24 24" aria-hidden="true" @class(["rain-blink" => $icon === "rain"])>
        @if ($icon === 'rain')
            <path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242" /><path d="M16 14v6" /><path d="M8 14v6" /><path d="M12 16v6" />
        @else
            <path d="M12.8 19.6A2 2 0 1 0 14 16H2" /><path d="M17.5 8a2.5 2.5 0 1 1 2 4H2" /><path d="M9.8 4.4A2 2 0 1 1 11 8H2" />
        @endif
    </svg>
    <span>{{ $slot }}</span>
</li>
