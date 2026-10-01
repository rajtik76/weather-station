{{-- What a single station cannot see (forecast/README.md), and the models and
     corrections that ran here, read off the stored forecasts (ForecastChanges). --}}
@php($changes = $this->changes)
<section class="page-wrap mt-20 grid gap-14 sm:mt-24 lg:grid-cols-2 lg:gap-16">
    <div aria-labelledby="limits-h">
        <h2 id="limits-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">What it cannot do</h2>
        <p class="m-0 mt-4 max-w-[60ch] text-[16px] leading-[1.7] text-ink-2">One station sees the weather only once it arrives. There is no radar and no neighbour upwind, so a front that is still an hour away is invisible.</p>
        <div class="mt-6 border-l-2 border-ch2 pl-5">
            <p class="num m-0 font-mono text-[14px] text-ink-3">24.9.2026</p>
            <p class="m-0 mt-1 max-w-[56ch] text-[16px] leading-relaxed text-ink-2">Rain from 06:20. The forecasts issued before it gave a 1-4 % chance: the air was still fairly dry, the pressure falling only moderately, and the front coming in from the west could not be seen from the balcony.</p>
        </div>
        <p class="m-0 mt-6 max-w-[60ch] text-[16px] leading-[1.7] text-ink-2">The morning sun on the shield is accepted, not fixed. The correction learns it instead, and on an overcast morning it still adds warmth that does not come: from temperature alone the station cannot tell the two kinds of morning apart.</p>
    </div>
    <div aria-labelledby="changes-h">
        <h2 id="changes-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Changelog</h2>
        @if ($changes === [])
            <p class="m-0 mt-5 text-[16px] text-ink-2">No forecast stored yet.</p>
        @else
            <ol class="m-0 mt-5 list-none p-0">
                @foreach ($changes as $change)
                    <li @class(['grid grid-cols-[96px_1fr] gap-4 border-t border-line py-3.5', 'border-b' => $loop->last])>
                        <span class="num font-mono text-[14px] text-ink-3">{{ $change['date'] }}</span>
                        <span class="text-[16px] text-ink-2">
                            @if ($change['model'] !== null)
                                The model trained <span class="font-mono text-[14px]">{{ $change['model'] }}</span> forecasts from here on.
                            @else
                                Correction {{ $change['correction'] }} applies from here on.
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>

{{-- CC BY 4.0 asks for the attribution. --}}
<section class="page-wrap mt-16" aria-label="Data credit">
    <p class="m-0 rounded-[10px] border border-line px-5 py-4 text-[15px] leading-relaxed text-ink-2 sm:px-6">Training data: ČHMÚ (Czech Hydrometeorological Institute) open data, 10-minute station records 2018-2024, licensed <span class="font-mono text-[14px]">CC BY 4.0</span>. Source: ČHMÚ. The forecasts on this site are the station's own and not a product of ČHMÚ.</p>
</section>
