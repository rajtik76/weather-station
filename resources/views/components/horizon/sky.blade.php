{{-- resources/js/horizon/hero.js paints everything here; a Livewire morph must never reach it. --}}
@props(['compact' => false])

<div class="hz-scene" data-scene data-rain="none" data-wind="calm" aria-hidden="true" wire:ignore>
    <div class="hz-stars" data-stars></div>
    <div class="hz-haze"></div>
    <div class="hz-sunglow hz-pin"></div>
    @unless ($compact)
        <svg class="hz-arc" viewBox="0 0 1000 1000" preserveAspectRatio="none">
            <polyline class="hz-arc-day" data-arc-day />
            <polyline class="hz-arc-passed" data-arc-passed />
            <polyline class="hz-arc-moon" data-arc-moon />
        </svg>
        <div class="hz-hours" data-hours></div>
    @endunless
    <div class="hz-sun hz-pin"></div>
    <div class="hz-moonglow hz-pin"></div>
    <div class="hz-moon hz-pin">
        <svg viewBox="-24 -24 48 48"><g data-moon-turn><circle class="hz-moon-dark" r="20" /><path class="hz-moon-lit" data-moon-lit /></g></svg>
        @unless ($compact)
            <span class="hz-moon-label" data-moon-label></span>
        @endunless
    </div>
    @foreach (['a', 'b', 'c'] as $cloud)
        <svg class="hz-cloud hz-cloud-{{ $cloud }}" viewBox="0 0 300 90"><path fill="currentColor" d="M30,72C4,72 4,46 28,45C30,22 62,14 78,33C92,6 140,4 152,35C170,20 200,26 204,47C232,41 252,54 246,68C244,73 238,72 234,72Z" /></svg>
    @endforeach
    <div class="hz-overcast"></div>
    <svg class="hz-gusts" viewBox="0 0 1600 600" preserveAspectRatio="xMidYMid slice">
        <path pathLength="1000" style="--d: 0s" d="M1650,150C1320,100 1160,200 900,150S520,100 400,160c-44,22-76-20-40-38c28-14 48,24 16,38" />
        <path pathLength="1000" style="--d: -1.9s" d="M1650,360C1400,320 1220,400 980,360S640,330 560,370" />
        <path pathLength="1000" style="--d: -0.8s" d="M1650,60C1380,30 1250,100 1040,70S760,40 680,90c-36,22-66-12-36-30c24-14 42,18 14,32" />
        <path pathLength="1000" style="--d: -3.1s" d="M1650,250C1300,220 1100,300 820,250S380,220 240,270" />
        <path pathLength="1000" style="--d: -1.3s" d="M1650,470C1420,440 1300,500 1120,470S900,450 840,490c-30,18-56-10-30-26" />
    </svg>
    <svg class="hz-city" data-city viewBox="0 0 1600 400"></svg>
    <div class="hz-mist"></div>
    <div class="hz-rain"><i></i><i></i><i></i></div>
    <div class="hz-leaves" data-leaves></div>
    <div class="hz-ground"></div>
</div>
