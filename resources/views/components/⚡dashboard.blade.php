<?php

use App\Models\Measurement;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Weather Station')] class extends Component
{
    /** Measurements arrive from the ESP32 every 10 minutes. */
    private const int STEP_SECONDS = 600;

    /** Sensor location: Galerie Slovany, náměstí Generála Píky, Plzeň-Slovany. */
    private const float LATITUDE = 49.732343;

    private const float LONGITUDE = 13.400984;

    /** The map draws this radius as a circle, with nothing marking its centre. */
    private const int LOCATION_RADIUS_METRES = 500;

    private const int DAYS = 31;

    /**
     * Stored readings for the chart window, oldest first.
     *
     * Columns hold the raw protocol units (see ProtocolVersion::V1):
     * temperature and humidity in hundredths, pressure in pascals. The chart
     * wants °C, % and hPa. The `d` field is the timestamp rendered as ISO 8601
     * for the chart's time axis.
     *
     * @return list<array{d: string, t: float, h: float, p: float}>
     */
    #[Computed]
    public function readings(): array
    {
        return Measurement::query()
            ->where('timestamp', '>=', now()->subDays(self::DAYS)->getTimestamp())
            ->orderBy('timestamp')
            ->get()
            ->map(fn (Measurement $measurement): array => [
                // Tagged UTC: Flux formats tick and tooltip labels with a forced
                // UTC time zone, so a naive stamp would render shifted.
                'd' => date('Y-m-d\TH:i:s\Z', $measurement->timestamp),
                't' => round($measurement->data->temperature / 100, 2),
                'h' => round($measurement->data->humidity / 100, 2),
                'p' => round($measurement->data->pressure / 100, 1),
            ])
            ->all();
    }

    /** No rows yet: the station has never reported, or not within the window. */
    #[Computed]
    public function hasReadings(): bool
    {
        return $this->readings !== [];
    }

    /** How far back the chart looks, for the empty state to name. */
    #[Computed]
    public function windowDays(): int
    {
        return self::DAYS;
    }

    /**
     * The trailing 24 hours, for the stat-card sparklines.
     *
     * @return list<array{d: string, t: float, h: float, p: float}>
     */
    #[Computed]
    public function lastDay(): array
    {
        return array_slice($this->readings, -(86400 / self::STEP_SECONDS + 1));
    }

    /**
     * Card + panel figures for one metric.
     *
     * @return array{now: float, delta: float, dayMin: float, dayMax: float, min: float, max: float, avg: float}
     */
    private function figures(string $field): array
    {
        $all = array_column($this->readings, $field);
        $day = array_column($this->lastDay, $field);
        $now = (float) end($day);

        return [
            'now' => $now,
            // vs. one hour ago, or the oldest point we have if the window is shorter
            'delta' => $now - (float) $day[max(0, count($day) - 7)],
            'dayMin' => (float) min($day),
            'dayMax' => (float) max($day),
            'min' => (float) min($all),
            'max' => (float) max($all),
            'avg' => array_sum($all) / count($all),
        ];
    }

    /**
     * @return array<string, array{now: float, delta: float, dayMin: float, dayMax: float, min: float, max: float, avg: float}>
     */
    #[Computed]
    public function metrics(): array
    {
        return [
            't' => $this->figures('t'),
            'h' => $this->figures('h'),
            'p' => $this->figures('p'),
        ];
    }

    /**
     * Round y-axis tick values inside each metric's data range.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function ticks(): array
    {
        $within = function (float $min, float $max, int $step): array {
            $values = [];
            for ($v = (int) ceil($min / $step) * $step; $v <= $max; $v += $step) {
                $values[] = $v;
            }

            return $values;
        };

        return [
            't' => $within($this->metrics['t']['min'], $this->metrics['t']['max'], 5),
            'p' => $within($this->metrics['p']['min'], $this->metrics['p']['max'], 10),
        ];
    }

    /**
     * Centre and radius of the area shown on the location map.
     *
     * @return array{lat: float, lng: float, radius: int}
     */
    #[Computed]
    public function approximateLocation(): array
    {
        return [
            'lat' => self::LATITUDE,
            'lng' => self::LONGITUDE,
            'radius' => self::LOCATION_RADIUS_METRES,
        ];
    }

    #[Computed]
    public function measuredAt(): ?string
    {
        if (! $this->hasReadings) {
            return null;
        }

        $last = $this->readings[count($this->readings) - 1];

        return Carbon::parse($last['d'])->format('j. n. Y H:i');
    }
};
?>

<div class="min-h-screen bg-zinc-50 font-sans text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">

    {{-- ── Top bar ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between gap-4 border-b border-zinc-900/10 px-4 py-3 font-mono text-[11px] font-medium tracking-[0.25em] text-zinc-500 uppercase sm:px-8 dark:border-white/10 dark:text-zinc-400">
        <span>Station log · ESP32 + BME280</span>
        <span class="hidden sm:inline">10-min interval · {{ $this->hasReadings ? 'last '.$this->measuredAt : 'no records yet' }}</span>
        <flux:button
            x-data
            x-on:click="$flux.dark = ! $flux.dark"
            variant="subtle"
            size="sm"
            icon="moon"
            aria-label="Toggle dark mode"
        />
    </div>

    {{-- ── Hero: title + giant readouts ───────────────────────────── --}}
    <header class="border-b border-zinc-900/10 px-4 pt-12 pb-10 sm:px-8 dark:border-white/10">
        <div class="flex flex-wrap items-end justify-between gap-x-16 gap-y-10">
            <div>
                <flux:heading level="1" class="font-display text-[clamp(3.5rem,12.5vw,11.5rem)]! leading-[0.78] font-extrabold! tracking-[-0.03em] uppercase">
                    Home<br>Weather<br>Station
                </flux:heading>
                <flux:text class="mt-6 max-w-sm text-sm">
                    The station reports every ten minutes, around the clock. Every
                    point on the chart is a raw record - nothing smoothed, nothing
                    averaged.
                </flux:text>
            </div>

            @if ($this->hasReadings)
            <div class="flex flex-col items-start gap-8 lg:items-end lg:text-right" aria-label="Current conditions">
                @foreach ([
                    ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 2, 'accent' => 'text-amber-600'],
                    ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 2, 'accent' => 'text-cyan-600'],
                    ['key' => 'p', 'label' => 'Pressure', 'unit' => 'hPa', 'dec' => 1, 'accent' => 'text-violet-600 dark:text-violet-500'],
                ] as $readout)
                    @php($m = $this->metrics[$readout['key']])
                    <div>
                        <p class="flex items-center gap-2 font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase lg:justify-end dark:text-zinc-400">
                            {{ $readout['label'] }}
                            <flux:icon
                                :icon="$m['delta'] >= 0.05 ? 'arrow-trending-up' : ($m['delta'] <= -0.05 ? 'arrow-trending-down' : 'minus')"
                                variant="micro"
                            />
                            {{ ($m['delta'] >= 0 ? '+' : '−') . number_format(abs($m['delta']), $readout['dec'], ',', ' ') }}/h
                        </p>
                        <p class="font-display mt-1 text-6xl leading-none font-bold sm:text-7xl">
                            {{ number_format($m['now'], $readout['dec'], ',', ' ') }}<span class="{{ $readout['accent'] }} ml-1 align-baseline text-2xl font-bold sm:text-3xl">{{ $readout['unit'] }}</span>
                        </p>
                        <p class="mt-2 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                            24 h · min {{ number_format($m['dayMin'], $readout['dec'], ',', ' ') }} · max {{ number_format($m['dayMax'], $readout['dec'], ',', ' ') }}
                        </p>
                    </div>
                @endforeach
            </div>
            @endif
        </div>
    </header>

    {{-- ── Channel strips ─────────────────────────────────────────── --}}
    @if (! $this->hasReadings)
        <section aria-label="No data" class="border-b border-zinc-900/10 px-4 py-20 text-center sm:px-8 dark:border-white/10">
            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                Waiting for the first reading
            </p>
            <flux:text class="mx-auto mt-4 max-w-sm text-sm">
                Nothing has been recorded in the last {{ $this->windowDays }} days. The chart appears
                once the station posts a measurement.
            </flux:text>
        </section>
    @else
    @foreach ([
        [
            'channel' => 'CH1',
            'label' => 'Temperature',
            'unit' => '°C',
            'aria' => 'Temperature history',
            'field' => 't',
            'dec' => 2,
            'line' => 'text-amber-600',
            'height' => 'h-72 sm:h-80',
            'tickStart' => 'min',
            'tickValues' => $this->ticks['t'],
            'tooltipLabel' => 'Temperature',
            'tooltipFormat' => ['style' => 'unit', 'unit' => 'celsius', 'minimumFractionDigits' => 2],
            'summaryFormat' => ['minimumFractionDigits' => 2, 'maximumFractionDigits' => 2],
            'brush' => true,
        ],
        [
            'channel' => 'CH2',
            'label' => 'Humidity',
            'unit' => '%',
            'aria' => 'Humidity history',
            'field' => 'h',
            'dec' => 2,
            'line' => 'text-cyan-600',
            'height' => 'h-48 sm:h-56',
            'tickStart' => null,
            'tickValues' => null,
            'tooltipLabel' => 'Humidity',
            'tooltipFormat' => ['style' => 'unit', 'unit' => 'percent', 'minimumFractionDigits' => 2],
            'summaryFormat' => ['minimumFractionDigits' => 2, 'maximumFractionDigits' => 2],
            'brush' => false,
        ],
        [
            'channel' => 'CH3',
            'label' => 'Pressure',
            'unit' => 'hPa',
            'aria' => 'Pressure history',
            'field' => 'p',
            'dec' => 1,
            'line' => 'text-violet-600 dark:text-violet-500',
            'height' => 'h-48 sm:h-56',
            'tickStart' => 'min',
            'tickValues' => $this->ticks['p'],
            'tooltipLabel' => 'Pressure (hPa)',
            'tooltipFormat' => ['minimumFractionDigits' => 1, 'maximumFractionDigits' => 1],
            'summaryFormat' => ['minimumFractionDigits' => 1, 'maximumFractionDigits' => 1, 'useGrouping' => false],
            'brush' => false,
        ],
    ] as $strip)
        @php($m = $this->metrics[$strip['field']])
        <section
            aria-label="{{ $strip['aria'] }}"
            class="border-b border-zinc-900/10 dark:border-white/10"
            @if ($strip['brush'])
                data-brush-field="{{ $strip['field'] }}"
                data-brush-decimals="{{ $strip['dec'] }}"
            @endif
        >
            <flux:chart :value="$this->readings" locale="cs">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-5 sm:px-8">
                    <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                        {{ $strip['channel'] }} · {{ $strip['label'] }} · {{ $strip['unit'] }}
                    </p>
                    <flux:chart.summary class="order-last w-full font-mono text-sm sm:order-none sm:w-auto">
                        <flux:chart.summary.value :field="$strip['field']" :format="$strip['summaryFormat']" class="font-medium tabular-nums" /><span class="text-zinc-400 dark:text-zinc-500"> {{ $strip['unit'] }} · hover to scrub @if ($strip['brush'])<span data-brush-hint>· drag to select</span>@endif</span>
                    </flux:chart.summary>
                    <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                        min {{ number_format($m['min'], $strip['dec'], ',', ' ') }}
                        · max {{ number_format($m['max'], $strip['dec'], ',', ' ') }}
                        · avg {{ number_format($m['avg'], $strip['dec'], ',', ' ') }}
                    </p>
                </div>

                @if ($strip['brush'])
                    <div
                        data-brush-readout
                        style="display: none"
                        aria-live="polite"
                        class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-y border-zinc-900/10 bg-zinc-900/[0.03] px-4 py-2 font-mono text-xs text-zinc-500 sm:px-8 dark:border-white/10 dark:bg-white/[0.04] dark:text-zinc-400"
                    >
                        <span class="font-medium tracking-[0.2em] text-zinc-800 uppercase dark:text-zinc-200">Selection</span>
                        <span data-brush-range class="text-zinc-800 dark:text-zinc-200"></span>
                        <span aria-hidden="true">·</span>
                        <span><span data-brush-count class="text-zinc-800 tabular-nums dark:text-zinc-200"></span> readings</span>
                        <span aria-hidden="true">·</span>
                        <span>min <span data-brush-min class="text-zinc-800 tabular-nums dark:text-zinc-200"></span></span>
                        <span aria-hidden="true">·</span>
                        <span>max <span data-brush-max class="text-zinc-800 tabular-nums dark:text-zinc-200"></span></span>
                        <span aria-hidden="true">·</span>
                        <span>avg <span data-brush-avg class="text-zinc-800 tabular-nums dark:text-zinc-200"></span></span>
                        <button
                            type="button"
                            data-brush-clear
                            class="ml-auto rounded-sm tracking-[0.2em] uppercase underline decoration-zinc-300 underline-offset-4 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:decoration-zinc-600 dark:hover:text-zinc-200 dark:focus-visible:outline-zinc-100"
                        >
                            Clear
                        </button>
                    </div>
                @endif

                <flux:chart.viewport
                    :class="$strip['height'].' w-full'.($strip['brush'] ? ' cursor-crosshair select-none' : '')"
                    data-brush-viewport
                >
                    <flux:chart.svg gutter="10 0 26 48">
                        <flux:chart.line :field="$strip['field']" curve="none" class="{{ $strip['line'] }}" stroke-width="1.5" />

                        <flux:chart.axis axis="x" field="d" :format="['day' => 'numeric', 'month' => 'numeric']" tick-count="8">
                            <flux:chart.axis.grid class="text-zinc-900/5 dark:text-white/5" />
                            <flux:chart.axis.tick class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500" />
                        </flux:chart.axis>
                        <flux:chart.axis
                            axis="y"
                            tick-count="5"
                            :tick-start="$strip['tickStart']"
                            :tick-end="$strip['tickStart'] ? 'max' : null"
                            :tick-values="$strip['tickValues'] ? json_encode($strip['tickValues']) : null"
                            :format="['useGrouping' => false]"
                        >
                            <flux:chart.axis.grid class="text-zinc-900/5 dark:text-white/5" />
                            <flux:chart.axis.tick class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500" />
                        </flux:chart.axis>

                        <flux:chart.cursor class="text-zinc-400 dark:text-zinc-500" stroke-dasharray="3,3" />
                    </flux:chart.svg>

                    @if ($strip['brush'])
                        <div
                            data-brush-band
                            style="display: none"
                            aria-hidden="true"
                            class="pointer-events-none absolute inset-y-0 border-x border-zinc-900/40 bg-zinc-900/10 dark:border-white/40 dark:bg-white/10"
                        ></div>
                    @endif

                    <flux:chart.tooltip class="top-0 left-0 z-10 pointer-events-none">
                        <flux:chart.tooltip.heading field="d" :format="['day' => 'numeric', 'month' => 'numeric', 'hour' => '2-digit', 'minute' => '2-digit']" />
                        <flux:chart.tooltip.value :field="$strip['field']" :label="$strip['tooltipLabel']" :format="$strip['tooltipFormat']" />
                    </flux:chart.tooltip>
                </flux:chart.viewport>
            </flux:chart>
        </section>
    @endforeach
    @endif

    {{-- ── Site location ──────────────────────────────────────────── --}}
    <section aria-label="Station location" class="border-b border-zinc-900/10 dark:border-white/10">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-5 pb-4 sm:px-8">
            <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                Site · Plzeň-Slovany, CZ
            </p>
            <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                approximate location · {{ number_format($this->approximateLocation['radius']) }} m radius
            </p>
        </div>

        <div
            wire:ignore
            data-station-map
            data-lat="{{ $this->approximateLocation['lat'] }}"
            data-lng="{{ $this->approximateLocation['lng'] }}"
            data-radius="{{ $this->approximateLocation['radius'] }}"
            class="h-64 w-full sm:h-72"
            role="img"
            aria-label="Map showing the approximate area the station reports from"
        ></div>
    </section>

    {{-- ── Footer ─────────────────────────────────────────────────── --}}
    <footer class="flex flex-wrap items-center justify-between gap-2 px-4 py-6 font-mono text-[11px] tracking-widest text-zinc-400 uppercase sm:px-8 dark:text-zinc-500">
        <span>{{ number_format(count($this->readings), 0, ',', ' ') }} records</span>
        <span class="hidden sm:inline">ESP32 → HTTP POST · unix time + t/h/p</span>
        <span>
            &copy; {{ now()->year }} Vladislav Rajtmajer ·
            <a
                href="https://github.com/rajtik76"
                target="_blank"
                rel="noopener noreferrer"
                class="rounded-sm underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:decoration-zinc-600 dark:hover:text-zinc-300 dark:focus-visible:outline-zinc-100"
            >GitHub</a>
        </span>
    </footer>
</div>
