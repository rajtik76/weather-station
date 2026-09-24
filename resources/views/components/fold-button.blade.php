{{-- The chevron that folds a block to its header. Needs `collapsed` in the
     Alpine scope around it; `collapsed` here is only where it starts, so the
     server renders the aria state the page opens with. --}}
@props([
    'controls',
    'label',
    'collapsed' => false,
])

<flux:button
    x-on:click="collapsed = ! collapsed"
    variant="subtle"
    size="xs"
    icon="chevron-up"
    aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
    x-bind:aria-expanded="collapsed ? 'false' : 'true'"
    aria-controls="{{ $controls }}"
    aria-label="{{ ($collapsed ? 'Expand ' : 'Collapse ').$label }}"
    x-bind:aria-label="collapsed ? 'Expand {{ $label }}' : 'Collapse {{ $label }}'"
    class="ml-auto"
    x-bind:class="{ '[&_svg]:rotate-180': collapsed }"
/>
