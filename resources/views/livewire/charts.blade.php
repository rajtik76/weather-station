{{-- No wire:poll here: the reader holds a window they chose, and only the
     overview refreshes itself. --}}
<div class="instrument min-h-screen">
    @include('livewire.overview.header', ['page' => 'charts'])

    <main id="main">
        @include('livewire.charts.window')
        @include('livewire.charts.strips')
        @include('livewire.charts.station')
    </main>

    @include('livewire.overview.footer')
</div>
