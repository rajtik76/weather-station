<div wire:poll.60s class="instrument min-h-screen">
    @include('livewire.overview.header')

    <main id="main">
        @include('livewire.overview.hero')
        @include('livewire.overview.about')
        @include('livewire.overview.channels')
        @include('livewire.overview.forecast')
        @include('livewire.overview.status')
        @include('livewire.overview.location')
    </main>

    @include('livewire.overview.footer')
</div>
