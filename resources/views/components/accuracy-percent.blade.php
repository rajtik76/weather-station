{{-- A forecast accuracy, coloured by AccuracyGrade. --}}
@props(['percent'])

<span {{ $attributes->class([
    'tabular-nums',
    match (\App\Enums\AccuracyGrade::of($percent)) {
        \App\Enums\AccuracyGrade::Good => 'text-emerald-600 dark:text-emerald-400',
        \App\Enums\AccuracyGrade::Fair => 'text-amber-600 dark:text-amber-500',
        \App\Enums\AccuracyGrade::Poor => 'text-red-600 dark:text-red-400',
    },
]) }}>{{ number_format($percent, 0) }} %</span>
