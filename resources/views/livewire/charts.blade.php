{{-- No wire:poll: the reader holds a window they chose. --}}
<x-horizon.page compact :year="$this->currentYear">
    <x-slot:hero>
        @include('livewire.overview.header', ['page' => 'charts'])
    </x-slot:hero>

    @include('livewire.charts.window')
    @include('livewire.charts.strips')
    @include('livewire.charts.station')
</x-horizon.page>
