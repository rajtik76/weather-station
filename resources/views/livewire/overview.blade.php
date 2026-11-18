<x-horizon.page wire:poll.60s :year="$this->currentYear">
    <x-slot:hero>
        @include('livewire.overview.hero')
    </x-slot:hero>

    @include('livewire.overview.channels')
    @include('livewire.overview.about')
    @include('livewire.overview.verdict')
    @include('livewire.overview.location')

    <x-slot:status>
        @include('livewire.overview.status')
    </x-slot:status>
</x-horizon.page>
