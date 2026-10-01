{{-- No wire:poll: the scores move once per upload. --}}
<div class="instrument min-h-screen">
    @include('livewire.overview.header', ['page' => 'forecast'])

    <main id="main">
        @include('livewire.forecast.question')
        @include('livewire.forecast.pipeline')
        @include('livewire.forecast.current')
        @include('livewire.forecast.horizons')
        @include('livewire.forecast.detail')
        @include('livewire.forecast.held-out')
        @include('livewire.forecast.limits')
    </main>

    @include('livewire.overview.footer')
</div>
