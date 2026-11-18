@php($summary = $this->referenceSummary)
<x-horizon.section aria-labelledby="reference-h">
    <x-horizon.heading id="reference-h">Checked against a professional station</x-horizon.heading>
    <p class="hz-lede">ČHMÚ Plzeň-Mikulka, about 3.5 km away, is the reference. The correction is fitted and judged against it, shifted by the balcony's own offset read while the shield stands in shade.</p>
    @if ($summary['count'] === 0)
        <p class="hz-lede">No matched corrected observations yet. A validated calibration, light measurements and reference data are needed.</p>
    @else
        @php($largest = fn (array $deviation): ?float => $deviation['min'] === null || $deviation['max'] === null ? null : max(abs($deviation['min']), abs($deviation['max'])))
        <div class="hz-verdict">
            <x-horizon.verdict title="Mean absolute deviation" :estimate="$summary['estimated']['mae']" :sensor="$summary['measured']['mae']" />
            <x-horizon.verdict title="Mean signed difference" :estimate="$summary['estimated']['bias']" :sensor="$summary['measured']['bias']" signed />
            <x-horizon.verdict title="Largest difference" :estimate="$largest($summary['estimated'])" :sensor="$largest($summary['measured'])" />
        </div>
        <p class="hz-card-note">Last 30 days, {{ \App\ValueObject\Figure::format($summary['count'], 0) }} matched observations, station minus the shade reference.</p>
    @endif
    <a class="hz-link" href="{{ route('comparison', $this->sensorQuery()) }}">Open the reference page</a>
</x-horizon.section>
