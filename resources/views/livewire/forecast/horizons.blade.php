@php($rows = $this->scoreboard)
<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="horizons-h">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-1">
        <h2 id="horizons-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Verdict by horizon</h2>
        <p class="m-0 font-mono text-[13px] text-ink-3">temperature · last {{ $this::ACCURACY_DAYS }} days · shown forecast</p>
    </div>

    @if ($rows === [])
        <p class="m-0 rounded-[10px] border border-line bg-screen px-6 py-10 text-ink-2">No forecast has come true yet.</p>
    @else
        <div class="overflow-x-auto rounded-[10px] border border-line bg-screen px-5 pt-5 pb-2 sm:px-6" tabindex="0" role="region" aria-label="Verdict by horizon, scrolls sideways">
            <table class="mtable min-w-[680px]">
                <thead>
                    <tr>
                        <th scope="col">Horizon</th>
                        <th scope="col" class="w-[34%]">Skill vs naive</th>
                        <th scope="col" class="w-[28%]">Reading in range</th>
                        <th scope="col">Range width</th>
                        <th scope="col">Forecasts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['hours'] }} h</td>
                            <td>
                                <span class="flex items-center justify-end gap-3">
                                    <span class="relative h-1.5 w-full max-w-[200px] rounded-full bg-line"><span class="absolute inset-y-0 left-0 rounded-full bg-ch1" style="width: {{ $row['skillBar'] }}%"></span></span>
                                    <span class="w-14">{{ $row['skill'] === null ? 'n/a' : \App\ValueObject\Figure::signed($row['skill'], 0).' %' }}</span>
                                </span>
                            </td>
                            <td>
                                <span class="flex items-center justify-end gap-3">
                                    <span class="relative h-1.5 w-full max-w-[160px] rounded-full bg-line">
                                        <span class="absolute inset-y-0 left-0 rounded-full bg-ink-2" style="width: {{ $row['inRangeBar'] }}%"></span>
                                        <span class="absolute -top-1 h-3.5 w-px bg-ink" style="left: 80%" aria-hidden="true"></span>
                                    </span>
                                    <span class="w-12">{{ \App\ValueObject\Figure::format($row['inRange'], 0) }} %</span>
                                </span>
                            </td>
                            <td>
                                {{ \App\ValueObject\Figure::format($row['width'], 1) }} °C
                                @if ($row['baseWidth'] !== null)
                                    <span class="text-ink-3">base {{ \App\ValueObject\Figure::format($row['baseWidth'], 1) }}</span>
                                @endif
                            </td>
                            <td class="dim">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="m-0 py-3 text-[14px] text-ink-3">
                Skill: how much smaller the forecast's miss is than the naive guess's. Below zero would mean worse. The tick marks the 80 % target.
            </p>
        </div>
    @endif
</section>
