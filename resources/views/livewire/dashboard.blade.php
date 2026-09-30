{{-- Polling, not broadcasting: one packet per ten minutes does not need a
     websocket. A zoomed window re-queries fixed epochs, so its payload comes
     back identical and the charts are not redrawn under the reader. --}}
<div
    wire:poll.60s
    class="sky-page min-h-screen font-sans font-medium text-slate-900 dark:text-slate-100"
>
    @php($tones = [
        't' => 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        'h' => 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
        'p' => 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        'n' => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        'spectrum' => 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
        'l' => 'bg-yellow-500/15 text-yellow-700 dark:text-yellow-400',
        'good' => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        'rain' => 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    ])

    <div class="mx-auto max-w-[1320px] px-3 pt-4 pb-16 sm:px-6">
        {{-- Each section is a partial in livewire/dashboard, rendered inside this
             component: $this, wire: directives and the variables set here
             ($tones, $forecast, $temperature, $hasReport) reach them unchanged. --}}
        @include('livewire.dashboard.top-bar')
        @include('livewire.dashboard.story')

        @php($forecast = $this->forecast)
        @php($temperature = $this->metrics['t'] ?? null)
        @php($hasReport = $this->stationReport !== null)

        <div class="grid grid-cols-12 gap-3 sm:gap-3.5">
            @include('livewire.dashboard.sky')
            @include('livewire.dashboard.verdict')
            @include('livewire.dashboard.how-it-works')
            @include('livewire.dashboard.readouts')
            @include('livewire.dashboard.accuracy')
            @include('livewire.dashboard.history')
            @include('livewire.dashboard.strips')
            @include('livewire.dashboard.transmissions')
            @include('livewire.dashboard.station-report')
            @include('livewire.dashboard.location')
        </div>

        @include('livewire.dashboard.footer')
    </div>
</div>
