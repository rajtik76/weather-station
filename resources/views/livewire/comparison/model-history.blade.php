@php($figure = fn (?float $value, bool $signed = false): string => $value === null ? '-' : ($signed ? \App\ValueObject\Figure::signed($value, 2) : \App\ValueObject\Figure::format($value, 2)))
<div class="mt-5 overflow-x-auto rounded-[10px] border border-line bg-screen">
    <table class="num w-full border-collapse text-left font-mono text-[14px]">
        <thead>
            <tr class="border-b border-line text-ink-2">
                @foreach (['Model', 'Status', 'Trained', 'Validated', 'Observations', 'Previous model MAE, °C', 'This model MAE, °C', 'Improvement, °C', 'Sun-facing improvement, °C', 'In use'] as $heading)
                    <th scope="col" class="px-5 py-4 font-normal whitespace-nowrap">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr @class(['border-b border-line last:border-0', 'bg-ch2/10 text-ch2' => $row['in_use_now']]) wire:key="model-history-{{ $row['id'] }}" @if ($row['in_use_now']) data-model-in-use @endif>
                    <th scope="row" class="px-5 py-3 font-normal whitespace-nowrap">#{{ $row['id'] }}</th>
                    <td class="px-5 py-3 whitespace-nowrap">{{ $row['in_use_now'] ? 'in use' : $row['status'] }}</td>
                    <td class="px-5 py-3 whitespace-nowrap">{{ \App\ValueObject\LocalTime::of($row['trained_from'])->day() }} - {{ \App\ValueObject\LocalTime::of($row['trained_until'] - 1)->day() }}</td>
                    <td class="px-5 py-3 whitespace-nowrap">{{ \App\ValueObject\LocalTime::of($row['trained_until'])->day() }} - {{ \App\ValueObject\LocalTime::of($row['validated_until'] - 1)->day() }}</td>
                    <td class="px-5 py-3">{{ $row['observations'] === null ? '-' : \App\ValueObject\Figure::format($row['observations'], 0) }}</td>
                    <td class="px-5 py-3">{{ $figure($row['previous_mae']) }}</td>
                    <td class="px-5 py-3">{{ $figure($row['own_mae']) }}</td>
                    <td class="px-5 py-3">{{ $figure($row['improvement'], true) }}</td>
                    <td class="px-5 py-3">{{ $figure($row['sun_improvement'], true) }}</td>
                    <td class="px-5 py-3 whitespace-nowrap">{{ $row['in_use_from'] === null ? '-' : \App\ValueObject\LocalTime::of($row['in_use_from'])->stamp().' - '.($row['in_use_until'] === null ? 'now' : \App\ValueObject\LocalTime::of($row['in_use_until'])->stamp()) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
