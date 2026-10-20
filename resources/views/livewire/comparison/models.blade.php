<dl class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="rounded-[10px] border border-line bg-screen px-5 py-4">
        <dt class="label-mono m-0 mb-2">Model in use</dt>
        @if ($inUse)
            <dd class="m-0 font-display text-[22px] font-semibold">Calibration #{{ $inUse->id }}</dd>
            <dd class="m-0 mt-2 text-[14px] leading-relaxed text-ink-2">Trained {{ \App\ValueObject\LocalTime::of($inUse->trained_from)->day() }} - {{ \App\ValueObject\LocalTime::of($inUse->trained_until - 1)->day() }}, validated {{ \App\ValueObject\LocalTime::of($inUse->trained_until)->day() }} - {{ \App\ValueObject\LocalTime::of($inUse->validated_until - 1)->day() }}. Correcting since {{ \App\ValueObject\LocalTime::of($inUse->validated_until)->stamp() }}.</dd>
        @else
            <dd class="m-0 text-[15px] text-ink-2">No accepted model. The shade estimate is unavailable.</dd>
        @endif
    </div>
    @if ($candidate?->isValidating())
        <div class="rounded-[10px] border border-line bg-screen px-5 py-4">
            <dt class="label-mono m-0 mb-2">Model under validation</dt>
            <dd class="m-0 font-display text-[22px] font-semibold text-ch2">Calibration #{{ $candidate->id }}</dd>
            <dd class="m-0 mt-2 text-[14px] leading-relaxed text-ink-2">Trained {{ \App\ValueObject\LocalTime::of($candidate->trained_from)->day() }} - {{ \App\ValueObject\LocalTime::of($candidate->trained_until - 1)->day() }}, validating on new readings {{ \App\ValueObject\LocalTime::of($candidate->trained_until)->day() }} - {{ \App\ValueObject\LocalTime::of($candidate->validated_until - 1)->day() }}. Decided after {{ \App\ValueObject\LocalTime::of($candidate->validated_until)->stamp() }}; it replaces the model in use only if it beats it.</dd>
        </div>
    @endif
</dl>
