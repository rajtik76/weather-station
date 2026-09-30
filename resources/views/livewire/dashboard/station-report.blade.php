@if ($hasReport)
    @php($report = $this->stationReport)
    <x-tile
        title="Station"
        icon="cpu"
        hint="as reported with the last upload"
        aria-label="Station report"
        :class="\Illuminate\Support\Arr::toCssClasses(['col-span-12', 'lg:col-span-5' => $this->recentTransmissions !== []])"
    >
        <x-slot:actions>
            <p class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $report['at'] }} · {{ $report['ago'] }}</p>
        </x-slot:actions>

        {{-- Board only from firmware 2.3, clock rows only once the board has measured a drift. --}}
        <dl class="grid grid-cols-2 gap-2">
            @foreach ([
                'firmware' => $report['firmware'],
                ...($report['board'] === null ? [] : ['board' => $report['board']]),
                'uptime' => $report['uptime'],
                'last reset' => $report['resetReason'],
                'network' => $report['network'],
                'rssi' => $report['rssi'].' dBm',
                'heap free' => number_format($report['heapFree'] / 1024, 0, ',', ' ').' kB',
                'heap lowest' => number_format($report['heapMin'] / 1024, 0, ',', ' ').' kB',
                'buffered' => $report['buffered'].' '.($report['buffered'] === 1 ? 'window' : 'windows'),
                'failed uploads' => $report['uploadFailures'].' in a row',
                'network switches' => $report['switches'],
                ...($report['clockDrift'] === null ? [] : [
                    'clock drift' => $report['clockDrift'],
                    'clock drift worst' => $report['clockDriftWorst'],
                    'clock synced' => $report['clockSynced'],
                ]),
            ] as $label => $value)
                <div class="min-w-0 rounded-[14px] bg-slate-900/[0.04] px-3 py-2.5 dark:bg-white/[0.04]">
                    <dt class="text-xs text-slate-500 first-letter:uppercase dark:text-slate-400">{{ $label }}</dt>
                    <dd class="mt-0.5 truncate font-mono text-[13px] font-medium tabular-nums">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-tile>
@endif
