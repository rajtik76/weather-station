{{-- Everything below is one sensor's record. The picker appears with a
     second sensor and sits beside the name, so it shifts nothing else. --}}
<nav aria-label="Station" class="flex flex-wrap items-center gap-3 px-1">
    <span class="text-[17px] font-extrabold tracking-[-0.02em]">{{ config('app.name') }}</span>
    <span class="flex-1"></span>

    <section aria-label="Sensor" class="pill">
        <span class="text-slate-500 dark:text-slate-400">Sensor</span>
        @if ($this->hasSensorChoice)
            {{-- The pill is the control's shape: the native select inside it loses its own
                 box and keeps only its text and chevron. --}}
            <flux:select
                wire:model.live="sensor"
                size="sm"
                class="h-7! w-auto! rounded-full! border-0! bg-transparent! py-0! ps-0! pe-6! text-[13.5px]! font-bold! text-slate-900! shadow-none! bg-position-[right_center]! bg-size-[1.25em]! focus-visible:outline-2 focus-visible:outline-offset-4 dark:bg-transparent! dark:text-slate-100!"
                aria-label="Choose a sensor"
            >
                @foreach ($this->sensors as $option)
                    <flux:select.option :value="$option->slug">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @elseif ($this->selectedSensor)
            <span class="font-bold" data-sensor-name>{{ $this->selectedSensor->name }}</span>
        @else
            <span class="text-slate-500 dark:text-slate-400">none registered yet</span>
        @endif
    </section>

    <button
        type="button"
        x-data
        x-on:click="$flux.dark = ! $flux.dark"
        class="pill size-[34px] justify-center p-0!"
        aria-label="Toggle dark mode"
    >
        <flux:icon.moon variant="mini" class="size-4 dark:hidden" />
        <flux:icon.sun variant="mini" class="hidden size-4 dark:block" />
    </button>
</nav>
