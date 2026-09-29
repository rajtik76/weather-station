{{-- One card on the sky: a titled header with its icon badge, the body below.
     `tone` colours the badge, `actions` sits at the right of the header. --}}
@props([
    'title',
    'icon',
    'tone' => 'bg-slate-500/10 text-slate-500 dark:text-slate-300',
    'hint' => null,
])

<section {{ $attributes->class('tile') }}>
    <div class="mb-3 flex flex-wrap items-center gap-x-3.5 gap-y-2">
        <h2 class="flex items-center gap-2.5 text-[17px] leading-tight font-extrabold tracking-[-0.015em]">
            <span class="{{ $tone }} grid size-[30px] shrink-0 place-items-center rounded-[10px]" aria-hidden="true">
                <flux:icon :icon="$icon" variant="mini" class="size-4" />
            </span>
            {{ $title }}
        </h2>
        @if ($hint)
            <p class="text-[13px] font-medium text-slate-500 dark:text-slate-400">{{ $hint }}</p>
        @endif
        @isset($actions)
            <div class="ml-auto flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    {{ $slot }}
</section>
