<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="pipeline-h">
    <h2 id="pipeline-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">How it works</h2>
    <div class="flow-h mt-8 hidden lg:block" aria-hidden="true"><span class="wire-electron"></span></div>
    <ol class="m-0 mt-6 grid list-none gap-x-10 gap-y-8 p-0 sm:grid-cols-2 lg:mt-0 lg:grid-cols-4">
        @foreach ([
            ['ČHMÚ data', '10-minute records from 32 professional stations below 700 m, 2018-2024. About 1,7 million hourly examples.'],
            ['Training', 'Gradient boosting per horizon, 1 to 6 h. Quantiles at 10, 50 and 90 % give a range meant to hold 8 readings in 10. Rain is a classifier: the chance of at least 0,1 mm.'],
            ['Station correction', 'A ridge regression learns this balcony\'s own error by solar time and from recent verified misses, refitted once a day. It widens or narrows the range until it holds 80 % of the readings.'],
            ['Score', 'Once its hour has passed, every forecast is compared with the reading and with the naive guess. "Base" is the model alone, "shown" is after the correction.'],
        ] as [$step, $text])
            <li class="relative border-l border-line-2 pl-5 lg:border-l-0 lg:pt-6 lg:pl-0">
                <span class="absolute top-1.5 -left-[5px] size-[9px] rounded-full bg-ink lg:-top-[4px] lg:left-0" aria-hidden="true"></span>
                <p class="label-mono m-0">Step {{ $loop->iteration }} · {{ $step }}</p>
                <p class="m-0 mt-2 text-[16px] leading-relaxed text-ink-2">{{ $text }}</p>
            </li>
        @endforeach
    </ol>
    <p class="m-0 mt-8 max-w-[80ch] text-[15px] leading-relaxed text-ink-3"><span class="font-mono text-ink-2">Inputs</span> · temperature and humidity now, distance from the dew point, changes over 1, 3, 6, 12, 24 and 48 h, pressure within its two-day swing, how unsettled the last 3 h were, rain heard in the last 1 h and 3 h, solar time and day of year. Nothing but the station's own readings.</p>
</section>
