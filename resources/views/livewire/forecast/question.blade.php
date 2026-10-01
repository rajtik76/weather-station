@php($verdict = $this->verdict)
<section class="page-wrap pt-10 sm:pt-14" aria-labelledby="question-h">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] lg:items-end lg:gap-16">
        <div class="max-w-[60ch]">
            <h1 id="question-h" class="m-0 font-display text-[30px] leading-[1.15] font-semibold tracking-[-0.015em] sm:text-[38px]">Can it beat “nothing changes”?</h1>
            <p class="m-0 mt-4 text-[18px] leading-[1.65] text-ink-2">A model trained on professional ČHMÚ stations forecasts the next six hours from this station's own readings. Each forecast is then scored against the naive guess that the temperature stays where it is.</p>
        </div>
        @if ($verdict === null || ! $verdict['ready'])
            <p class="m-0 rounded-[10px] border border-line bg-screen p-5 text-[16px] text-ink-2">Too early to tell. The verdict needs a day of forecasts that have come true.</p>
        @else
            <dl class="m-0 grid grid-cols-3 overflow-hidden rounded-[10px] border border-line bg-screen">
                <div class="border-r border-line p-4 sm:p-5">
                    <dt class="label-mono">Skill</dt>
                    <dd class="num m-0 mt-2 font-mono text-[30px] leading-none font-medium tracking-[-0.02em] sm:text-[36px]">{{ \App\ValueObject\Figure::signed($verdict['skill'], 0) }}<span class="text-[16px] font-normal text-ink-3"> %</span></dd>
                </div>
                <div class="border-r border-line p-4 sm:p-5">
                    <dt class="label-mono">Mean miss</dt>
                    <dd class="num m-0 mt-2 font-mono text-[30px] leading-none font-medium tracking-[-0.02em] sm:text-[36px]">{{ \App\ValueObject\Figure::format($verdict['error'], 2) }}<span class="text-[16px] font-normal text-ink-3"> °C</span></dd>
                    <dd class="num m-0 mt-1.5 font-mono text-[13px] text-ink-3">naive {{ \App\ValueObject\Figure::format($verdict['naive'], 2) }}</dd>
                </div>
                <div class="p-4 sm:p-5">
                    <dt class="label-mono">In range</dt>
                    <dd class="num m-0 mt-2 font-mono text-[30px] leading-none font-medium tracking-[-0.02em] sm:text-[36px]">{{ \App\ValueObject\Figure::format($verdict['inRange'], 0) }}<span class="text-[16px] font-normal text-ink-3"> %</span></dd>
                    <dd class="num m-0 mt-1.5 font-mono text-[13px] text-ink-3">target 80</dd>
                </div>
            </dl>
        @endif
    </div>
    @if ($verdict !== null && $verdict['ready'])
        <p class="m-0 mt-3 text-right font-mono text-[13px] text-ink-3">{{ $verdict['hours'] }} h ahead · last {{ $this::ACCURACY_DAYS }} days</p>
    @endif
</section>
