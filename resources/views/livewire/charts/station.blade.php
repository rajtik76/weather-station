{{-- The newest windows as they were stored, and the board's own report as the
     firmware sent it. Never the SSID or the IP: the page is public. --}}
@php($report = $this->stationReport)
@if ($this->recentTransmissions !== [] || $report !== null)
    <section id="station" class="page-wrap mt-20 grid gap-6 sm:mt-24 lg:grid-cols-[minmax(0,1fr)_380px]" aria-labelledby="station-h">
        <h2 id="station-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px] lg:col-span-2">Station</h2>

        @if ($this->recentTransmissions !== [])
            <div class="min-w-0 rounded-[10px] border border-line bg-screen p-5 sm:p-6" aria-label="Last transmissions">
                <p class="label-mono m-0">Last {{ count($this->recentTransmissions) }} windows · when they arrived</p>
                <ol class="m-0 mt-4 list-none space-y-5 p-0">
                    @foreach ($this->recentTransmissions as $transmission)
                        <li class="min-w-0 border-t border-line pt-4 first:border-t-0 first:pt-0">
                            <p class="num m-0 flex flex-wrap gap-x-5 gap-y-1 font-mono text-[14px]">
                                <span class="text-ink">{{ $transmission['at'] }}</span>
                                <span class="text-ink-3">{{ $transmission['ago'] }}</span>
                                <span><span class="text-ch1">CH1</span> {{ \App\ValueObject\Figure::format($transmission['t'], 2) }} °C</span>
                                <span><span class="text-ch2">CH2</span> {{ \App\ValueObject\Figure::format($transmission['h'], 2) }} %</span>
                                <span><span class="text-ch3">CH3</span> {{ \App\ValueObject\Figure::format($transmission['p'], 1) }} hPa</span>
                            </p>
                            <pre class="m-0 mt-2 overflow-x-auto rounded-md bg-bg px-3 py-2 font-mono text-[12.5px] leading-relaxed text-ink-2">{{ json_encode($transmission['packet'], JSON_UNESCAPED_SLASHES) }}</pre>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if ($report !== null)
            <div class="rounded-[10px] border border-line bg-screen p-5 sm:p-6" aria-label="Station report">
                <p class="label-mono m-0">Report · {{ $report['at'] }}</p>
                <dl class="num m-0 mt-4 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 font-mono text-[14px]">
                    @foreach (array_filter([
                        'firmware' => $report['firmware'],
                        'board' => $report['board'],
                        'uptime' => $report['uptime'],
                        'last reset' => $report['resetReason'],
                        'network' => $report['network'],
                        'RSSI' => \App\ValueObject\Figure::withMinus($report['rssi']).' dBm',
                        'heap free' => \App\ValueObject\Figure::format($report['heapFree'] / 1024, 0).' kB',
                        'heap lowest' => \App\ValueObject\Figure::format($report['heapMin'] / 1024, 0).' kB',
                        'buffered' => $report['buffered'].' '.($report['buffered'] === 1 ? 'window' : 'windows'),
                        'failed uploads' => $report['uploadFailures'].' in a row',
                        'network switches' => $report['switches'],
                        'clock drift' => $report['clockDrift'],
                        'clock drift worst' => $report['clockDriftWorst'],
                        'clock synced' => $report['clockSynced'],
                    ], fn (mixed $value): bool => $value !== null) as $name => $value)
                        <dt class="text-ink-3">{{ $name }}</dt>
                        <dd class="m-0 text-ink">{{ $value }}</dd>
                    @endforeach
                </dl>
            </div>
        @endif
    </section>
@endif
