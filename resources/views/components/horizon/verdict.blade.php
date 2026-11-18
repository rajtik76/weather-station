{{-- A 30-day deviation, shade estimate against the shield sensor; the longer bar spans the column. --}}
@props([
    'title',
    'estimate',
    'sensor',
    'signed' => false,
])

@php($figure = fn (?float $value): string => $value === null ? '-' : ($signed ? \App\ValueObject\Figure::signed($value, 2) : \App\ValueObject\Figure::format($value, 2)))
@php($longest = max(abs($estimate ?? 0.0), abs($sensor ?? 0.0)))
@php($width = fn (?float $value): float => $value === null || $longest == 0.0 ? 0.0 : round(abs($value) / $longest * 100, 1))
<div>
    <h3>{{ $title }}</h3>
    <span class="hz-fig">{{ $figure($estimate) }}<span class="hz-unit">°C</span></span>
    <ul class="hz-bars">
        <li>Estimate {{ $figure($estimate) }}<i style="--w: {{ $width($estimate) }}; background: var(--ref)"></i></li>
        <li>Sensor {{ $figure($sensor) }}<i style="--w: {{ $width($sensor) }}; background: var(--ch1)"></i></li>
    </ul>
</div>
