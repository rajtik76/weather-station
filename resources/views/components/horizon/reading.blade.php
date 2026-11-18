{{-- One figure of the day: its value now, the 24 h line in a 0-100 box, and a note. --}}
@props([
    'label',
    'value',
    'unit',
    'trace' => null,
    'silent' => false,
])

<div class="hz-reading" @if ($silent) data-silent @endif>
    <dt>{{ $label }}</dt>
    <dd><span class="hz-fig">{{ $value }}</span><span class="hz-unit">{{ $unit }}</span></dd>
    @if ($trace !== null)
        <svg class="hz-spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><path d="{{ $trace }}" /></svg>
    @endif
    <p class="hz-reading-note">{{ $slot }}</p>
</div>
