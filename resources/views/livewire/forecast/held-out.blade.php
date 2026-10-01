{{-- The model's own exam, fixed at training: forecast/README.md (Results)
     and forecast/CHANGELOG.md (Correction 2). Update them together. --}}
<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="held-h">
    <div class="mb-6 max-w-[70ch]">
        <h2 id="held-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Held-out results</h2>
        <p class="m-0 mt-3 text-[16px] leading-relaxed text-ink-2">2025 data from four stations the model never saw: Plzeň-Mikulka, Cheb, Kuchařovice and Pardubice. Mean absolute error, model against persistence.</p>
    </div>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div class="min-w-0 overflow-x-auto rounded-[10px] border border-line bg-screen px-5 pt-5 pb-2 sm:px-6" tabindex="0" role="region" aria-label="Held-out mean absolute error, scrolls sideways">
            <table class="mtable min-w-[560px]">
                <thead>
                    <tr><th scope="col">Horizon</th><th scope="col">T model</th><th scope="col">T naive</th><th scope="col">RH model</th><th scope="col">RH naive</th><th scope="col">p model</th><th scope="col">p naive</th></tr>
                </thead>
                <tbody>
                    @foreach ([
                        ['1 h', '0,50', '0,82', '2,7', '3,6', '0,19', '0,30'],
                        ['3 h', '0,94', '2,09', '4,7', '8,5', '0,44', '0,77'],
                        ['6 h', '1,38', '3,66', '6,4', '14,4', '0,85', '1,36'],
                    ] as [$ahead, $t, $tNaive, $h, $hNaive, $p, $pNaive])
                        <tr><td>{{ $ahead }}</td><td>{{ $t }}</td><td class="dim">{{ $tNaive }}</td><td>{{ $h }}</td><td class="dim">{{ $hNaive }}</td><td>{{ $p }}</td><td class="dim">{{ $pNaive }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="m-0 py-3 text-[14px] text-ink-3">T in °C, RH in %, p in hPa. The range held 76-81 % of readings.</p>
        </div>
        <div class="min-w-0 overflow-x-auto rounded-[10px] border border-line bg-screen px-5 pt-5 pb-2 sm:px-6" tabindex="0" role="region" aria-label="Held-out rain scores, scrolls sideways">
            <table class="mtable min-w-[320px]">
                <thead><tr><th scope="col">Rain</th><th scope="col">Brier</th><th scope="col">Climate</th><th scope="col">ROC AUC</th></tr></thead>
                <tbody>
                    <tr><td>1 h</td><td>0,039</td><td class="dim">0,068</td><td>0,86</td></tr>
                    <tr><td>6 h</td><td>0,103</td><td class="dim">0,148</td><td>0,79</td></tr>
                </tbody>
            </table>
            <p class="m-0 py-3 text-[14px] text-ink-3">Brier: lower is better. AUC for rain onset.</p>
        </div>
    </div>
    <div class="mt-6 rounded-[10px] border border-line px-5 py-4 sm:px-6">
        <p class="num m-0 flex flex-wrap items-baseline gap-x-8 gap-y-2 font-mono text-[14px]">
            <span class="font-sans text-[15px] text-ink-2">On the balcony, correction 2, 20.-26.9.2026</span>
            <span><span class="text-ink-3">1 h</span> 0,89 °C <span class="text-ink-3">(no correction 0,92)</span></span>
            <span><span class="text-ink-3">mornings 6-11 h, 3 h</span> 2,03 °C <span class="text-ink-3">(correction 1: 2,45)</span></span>
            <span><span class="text-ink-3">6 h</span> 1,92 °C <span class="text-ink-3">(2,36)</span></span>
        </p>
    </div>
</section>
