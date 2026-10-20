@if ($candidate?->isValidating())
    <div class="mt-6 max-w-xl rounded-[10px] border border-line bg-screen px-5 py-4">
        <p class="label-mono m-0 mb-2">Model under validation</p>
        <p class="m-0 font-display text-[22px] font-semibold text-ch2">Calibration #{{ $candidate->id }}</p>
        <p class="m-0 mt-2 text-[14px] leading-relaxed text-ink-2">Trained {{ \App\ValueObject\LocalTime::of($candidate->trained_from)->day() }} - {{ \App\ValueObject\LocalTime::of($candidate->trained_until - 1)->day() }}, validating on new readings {{ \App\ValueObject\LocalTime::of($candidate->trained_until)->day() }} - {{ \App\ValueObject\LocalTime::of($candidate->validated_until - 1)->day() }}. Decided after {{ \App\ValueObject\LocalTime::of($candidate->validated_until)->stamp() }}; it replaces the model in use only if it beats it.</p>
    </div>
@endif
