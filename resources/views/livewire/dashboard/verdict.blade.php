{{-- The answer to the story's question; history, so it stays while a forecast is stale. Dashboard::verdict(). --}}
@if ($this->verdict !== null)
    @php($verdict = $this->verdict)
    <x-tile
        id="verdict"
        title="Is it working?"
        icon="circle-check"
        :tone="$tones['good']"
        hint="Last {{ $this::ACCURACY_DAYS }} days, {{ $verdict['count'] }} {{ Str::plural('forecast', $verdict['count']) }} scored"
        aria-label="Verdict"
        class="col-span-12 scroll-mt-4 lg:col-span-5"
    >
        @if (! $verdict['ready'])
            <p class="text-[15px] leading-normal font-semibold">
                Too early to say: the verdict waits for a day of forecasts that have come true.
            </p>
        @else
            @php($beats = $verdict['skill'] >= 0)
            {{-- The ring fills with the share the miss shrank by; a loss draws in rose from the same start. --}}
            @php($filled = min(100, abs($verdict['skill'])))
            <div class="grid items-center gap-x-5 gap-y-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                <div class="relative size-[132px]">
                    <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true">
                        <circle cx="60" cy="60" r="54" fill="none" stroke-width="11" @class(['stroke-emerald-500/15' => $beats, 'stroke-rose-500/15' => ! $beats]) />
                        <circle
                            cx="60" cy="60" r="54" fill="none" stroke-width="11" stroke-linecap="round"
                            @class(['stroke-emerald-500' => $beats, 'stroke-rose-500' => ! $beats])
                            stroke-dasharray="339.29"
                            stroke-dashoffset="{{ round(339.29 * (1 - $filled / 100), 2) }}"
                        />
                    </svg>
                    <div class="absolute inset-0 grid place-content-center text-center">
                        <span @class([
                            'text-[34px] leading-none font-extrabold tracking-[-0.04em]',
                            'text-rose-600 dark:text-rose-400' => ! $beats,
                        ])>{{ number_format(abs($verdict['skill']), 0) }} %</span>
                        <span class="text-[11.5px] text-slate-500 dark:text-slate-400">{{ $beats ? 'smaller' : 'larger' }} miss</span>
                    </div>
                </div>

                <div>
                    <p class="text-[15px] leading-normal font-semibold">
                        A {{ number_format(abs($verdict['skill']), 0) }} % {{ $beats ? 'smaller' : 'larger' }} miss than assuming it stays as warm as now, {{ $verdict['hours'] }} h ahead.
                    </p>
                    <dl class="mt-3 grid grid-cols-2 gap-2.5">
                        <div class="rounded-[14px] bg-slate-900/[0.04] px-3 py-2.5 dark:bg-white/[0.04]">
                            <dt class="sr-only">Off on average</dt>
                            <dd class="text-[22px] leading-tight font-extrabold tracking-[-0.03em]">{{ number_format($verdict['error'], 1, ',', ' ') }} °C</dd>
                            <dd class="text-[12.5px] text-slate-500 dark:text-slate-400">off on average, the naive guess {{ number_format($verdict['naive'], 1, ',', ' ') }} °C</dd>
                        </div>
                        <div class="rounded-[14px] bg-slate-900/[0.04] px-3 py-2.5 dark:bg-white/[0.04]">
                            <dt class="sr-only">Inside the forecast range</dt>
                            <dd class="text-[22px] leading-tight font-extrabold tracking-[-0.03em]">{{ number_format($verdict['inRange'], 0) }} %</dd>
                            <dd class="text-[12.5px] text-slate-500 dark:text-slate-400">inside the forecast range, target 80 %</dd>
                        </div>
                    </dl>
                </div>

                {{-- Every horizon beside the headline, so six hours is not the only number picked.
                     Each hour ahead has its own hue, near to far; a loss still reads rose in its figure. --}}
                @php($horizonTones = [
                    1 => ['bg-emerald-500/15', 'text-emerald-700 dark:text-emerald-300'],
                    2 => ['bg-teal-500/15', 'text-teal-700 dark:text-teal-300'],
                    3 => ['bg-cyan-500/15', 'text-cyan-700 dark:text-cyan-300'],
                    4 => ['bg-sky-500/15', 'text-sky-700 dark:text-sky-300'],
                    5 => ['bg-indigo-500/15', 'text-indigo-700 dark:text-indigo-300'],
                    6 => ['bg-violet-500/15', 'text-violet-700 dark:text-violet-300'],
                ])
                <ol class="grid grid-cols-3 gap-1.5 sm:col-span-2 sm:grid-cols-6" aria-label="Smaller miss than the naive guess by hours ahead">
                    @foreach ($verdict['horizons'] as $horizon)
                        @php($skill = $horizon['skill'])
                        @php([$horizonBackground, $horizonInk] = $horizonTones[$horizon['hours']] ?? ['bg-slate-900/[0.04] dark:bg-white/[0.04]', 'text-slate-500 dark:text-slate-400'])
                        <li class="{{ $horizonBackground }} rounded-xl px-1.5 py-2 text-center">
                            <span class="{{ $horizonInk }} block font-mono text-[11.5px]">+{{ $horizon['hours'] }} h</span>
                            <span @class([
                                'block text-sm font-extrabold tabular-nums',
                                'text-rose-600 dark:text-rose-400' => $skill !== null && $skill < 0,
                            ])>{{ $skill === null ? 'n/a' : ($skill > 0 ? '+' : '').number_format($skill, 0).' %' }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </x-tile>
@endif
