<div class="mt-5 overflow-x-auto rounded-[10px] border border-line bg-screen">
    <table class="num w-full border-collapse text-left font-mono text-[14px]">
        <thead>
            <tr class="border-b border-line text-ink-2">
                <th scope="col" class="px-5 py-4 font-normal">Difference, °C</th>
                @foreach ($columns as $column)
                    <th scope="col" class="px-5 py-4 font-normal whitespace-nowrap"><span class="{{ $column['class'] }}">{{ $column['label'] }}</span></th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach (['mae' => 'Mean absolute deviation', 'bias' => 'Mean signed difference', 'min' => 'Minimum signed difference', 'max' => 'Maximum signed difference'] as $row => $label)
                <tr class="border-b border-line last:border-0" wire:key="{{ $key }}-{{ $row }}">
                    <th scope="row" class="px-5 py-3 font-normal text-ink-2">{{ $label }}</th>
                    @foreach (array_keys($columns) as $series)
                        @php($value = $deviations[$series][$row])
                        <td class="px-5 py-3" wire:key="{{ $key }}-{{ $row }}-{{ $series }}">{{ $value === null ? '-' : ($row === 'mae' ? \App\ValueObject\Figure::format($value, 2) : \App\ValueObject\Figure::signed($value, 2)) }}</td>
                    @endforeach
                </tr>
            @endforeach
            @if (array_key_exists('count', $deviations[array_key_first($columns)]))
                <tr class="border-b border-line last:border-0" wire:key="{{ $key }}-count">
                    <th scope="row" class="px-5 py-3 font-normal text-ink-2">Matched observations</th>
                    @foreach (array_keys($columns) as $series)
                        <td class="px-5 py-3" wire:key="{{ $key }}-count-{{ $series }}">{{ \App\ValueObject\Figure::format($deviations[$series]['count'], 0) }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
</div>
