{{-- The board's own report, as the firmware sent it; nothing here is derived on the server. --}}
@php($report = $this->stationReport)
@if ($report !== null)
    <section class="page-wrap mt-16" aria-labelledby="acq-h">
        <h2 id="acq-h" class="sr-only">Station status</h2>
        <div class="num flex flex-wrap items-center gap-x-8 gap-y-2.5 rounded-[10px] border border-line px-5 py-4 font-mono text-[14px] sm:px-6">
            <span class="flex items-center gap-2.5 font-medium text-ink"><span @class(['electron', 'text-ink' => ! $this->isSilent, 'text-ink-3' => $this->isSilent]) aria-hidden="true"></span>{{ $this->isSilent ? 'ACQ STOP' : 'ACQ RUN' }}</span>
            <span><span class="text-ink-3">report</span> {{ $report['at'] }}</span>
            <span><span class="text-ink-3">RSSI</span> {{ \App\ValueObject\Figure::withMinus($report['rssi']) }} dBm</span>
            <span><span class="text-ink-3">uptime</span> {{ $report['uptime'] }}</span>
            <span><span class="text-ink-3">buffered</span> {{ $report['buffered'] }}</span>
            <span><span class="text-ink-3">failures</span> {{ $report['uploadFailures'] }}</span>
            <span><span class="text-ink-3">fw</span> {{ $report['firmware'] }}</span>
            <a class="link-ink font-sans text-[15px] lg:ml-auto" href="{{ route('charts', $this->sensorQuery()) }}#station">Station report</a>
        </div>
    </section>
@endif
