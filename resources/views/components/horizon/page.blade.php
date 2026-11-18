{{-- Every page: the sky with its nav on top, the page below, the footer. A compact sky is a band, not a screen. --}}
@props([
    'year',
    'compact' => false,
])

<div {{ $attributes->class('instrument min-h-screen') }}>
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-screen focus:px-3 focus:py-2">Skip to content</a>
    <header data-horizon @class(['hz-hero', 'hz-hero-compact' => $compact])>
        <x-horizon.sky :compact="$compact" />
        {{ $hero }}
    </header>

    <main id="main" class="hz-main">
        {{ $slot }}
    </main>

    <x-horizon.footer :year="$year">{{ $status ?? '' }}</x-horizon.footer>
</div>
