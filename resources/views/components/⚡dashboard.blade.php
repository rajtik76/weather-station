<?php

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

    /** The map shows this radius around the centre, never an exact pin. */
    private const int LOCATION_RADIUS_METRES = 500;

    private const int DAYS = 31;

    /**
     * One month of simulated BME280 readings, oldest first.
     *
     * Stands in for real ESP32 payloads (unix timestamp + temperature,
     * humidity, pressure) until the backend lands. The `d` field is the
     * timestamp rendered as ISO 8601 for the chart's time axis.
     *
     * @return list<array{d: string, t: float, h: int, p: float}>
     */
    #[Computed]
    public function readings(): array
    {
        mt_srand(1898);

        $count = self::DAYS * 86400 / self::STEP_SECONDS + 1;
        $end = (int) (floor(now()->getTimestamp() / self::STEP_SECONDS) * self::STEP_SECONDS);
        $start = $end - ($count - 1) * self::STEP_SECONDS;

        $rows = [];
        $temperatureDrift = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $timestamp = $start + $i * self::STEP_SECONDS;
            $days = ($timestamp - $start) / 86400;
            $hours = ((int) date('G', $timestamp)) + ((int) date('i', $timestamp)) / 60;

            // Passing weather fronts as overlapping slow pressure waves.
            $pressure = 1013.0
                + 9.0 * sin(2 * M_PI * $days / 5.3 + 0.9)
                + 5.0 * sin(2 * M_PI * $days / 2.4 + 2.1)
                + 2.5 * sin(2 * M_PI * $days / 11.0 + 4.4)
                + mt_rand(-15, 15) / 100;

            // -1 (deep low) .. +1 (strong high), drives the other two series.
            $front = max(-1.0, min(1.0, ($pressure - 1013.0) / 11.0));

            // Late-summer cooling plus a diurnal wave peaking at 15:00; clear
            // high-pressure days swing harder than overcast lows.
            $temperatureDrift = max(-1.5, min(1.5, $temperatureDrift + mt_rand(-100, 100) / 100 * 0.06));
            $seasonal = 18.5 - 3.5 * $days / self::DAYS;
            $amplitude = 5.5 + 2.0 * $front;
            $temperature = $seasonal
                + 1.6 * $front
                + $amplitude * sin(2 * M_PI * ($hours - 9) / 24)
                + $temperatureDrift
                + mt_rand(-15, 15) / 100;

            $humidity = 72.0
                - 2.6 * ($temperature - $seasonal)
                - 6.0 * $front
                + mt_rand(-200, 200) / 100;

            if ($front < -0.75) {
                $humidity += 18.0; // rain under a deep low
            }

            $rows[] = [
                'd' => date('Y-m-d\TH:i:s', $timestamp),
                't' => round($temperature, 1),
                'h' => (int) round(max(30.0, min(98.0, $humidity))),
                'p' => round($pressure, 1),
            ];
        }

        return $rows;
    }

    /**
     * The trailing 24 hours, for the stat-card sparklines.
     *
     * @return list<array{d: string, t: float, h: int, p: float}>
     */
    #[Computed]
    public function lastDay(): array
    {
        return array_slice($this->readings, -145);
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
            'delta' => $now - (float) $day[count($day) - 7], // vs. one hour ago
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
    public function measuredAt(): string
    {
        $last = $this->readings[count($this->readings) - 1];

        return \Illuminate\Support\Carbon::parse($last['d'])->format('j. n. Y H:i');
    }
};
?>

<div class="min-h-screen bg-zinc-50 font-sans text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">

    {{-- ── Top bar ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between gap-4 border-b border-zinc-900/10 px-4 py-3 font-mono text-[11px] font-medium tracking-[0.25em] text-zinc-500 uppercase sm:px-8 dark:border-white/10 dark:text-zinc-400">
        <span>Station log · ESP32 + BME280</span>
        <span class="hidden sm:inline">10-min interval · last {{ $this->measuredAt }}</span>
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

            <div class="flex flex-col items-start gap-8 lg:items-end lg:text-right" aria-label="Current conditions">
                @foreach ([
                    ['key' => 't', 'label' => 'Temperature', 'unit' => '°C', 'dec' => 1, 'accent' => 'text-amber-600'],
                    ['key' => 'h', 'label' => 'Humidity', 'unit' => '%', 'dec' => 0, 'accent' => 'text-cyan-600'],
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
        </div>
    </header>

    {{-- ── Channel strips ─────────────────────────────────────────── --}}
    @foreach ([
        [
            'channel' => 'CH1',
            'label' => 'Temperature',
            'unit' => '°C',
            'aria' => 'Temperature history',
            'field' => 't',
            'dec' => 1,
            'line' => 'text-amber-600',
            'height' => 'h-72 sm:h-80',
            'tickStart' => 'min',
            'tickValues' => $this->ticks['t'],
            'tooltipLabel' => 'Temperature',
            'tooltipFormat' => ['style' => 'unit', 'unit' => 'celsius', 'minimumFractionDigits' => 1],
            'summaryFormat' => ['minimumFractionDigits' => 1, 'maximumFractionDigits' => 1],
        ],
        [
            'channel' => 'CH2',
            'label' => 'Humidity',
            'unit' => '%',
            'aria' => 'Humidity history',
            'field' => 'h',
            'dec' => 0,
            'line' => 'text-cyan-600',
            'height' => 'h-48 sm:h-56',
            'tickStart' => null,
            'tickValues' => null,
            'tooltipLabel' => 'Humidity',
            'tooltipFormat' => ['style' => 'unit', 'unit' => 'percent'],
            'summaryFormat' => ['maximumFractionDigits' => 0],
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
        ],
    ] as $strip)
        @php($m = $this->metrics[$strip['field']])
        <section aria-label="{{ $strip['aria'] }}" class="border-b border-zinc-900/10 dark:border-white/10">
            <flux:chart :value="$this->readings" locale="cs">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-4 pt-5 sm:px-8">
                    <p class="font-mono text-[11px] font-medium tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
                        {{ $strip['channel'] }} · {{ $strip['label'] }} · {{ $strip['unit'] }}
                    </p>
                    <flux:chart.summary class="order-last w-full font-mono text-sm sm:order-none sm:w-auto">
                        <flux:chart.summary.value :field="$strip['field']" :format="$strip['summaryFormat']" class="font-medium tabular-nums" /><span class="text-zinc-400 dark:text-zinc-500"> {{ $strip['unit'] }} · hover to scrub</span>
                    </flux:chart.summary>
                    <p class="font-mono text-xs text-zinc-500 dark:text-zinc-400">
                        min {{ number_format($m['min'], $strip['dec'], ',', ' ') }}
                        · max {{ number_format($m['max'], $strip['dec'], ',', ' ') }}
                        · avg {{ number_format($m['avg'], $strip['dec'], ',', ' ') }}
                    </p>
                </div>

                <flux:chart.viewport class="{{ $strip['height'] }} w-full">
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

                    <flux:chart.tooltip class="top-0 left-0 z-10 pointer-events-none">
                        <flux:chart.tooltip.heading field="d" :format="['day' => 'numeric', 'month' => 'numeric', 'hour' => '2-digit', 'minute' => '2-digit']" />
                        <flux:chart.tooltip.value :field="$strip['field']" :label="$strip['tooltipLabel']" :format="$strip['tooltipFormat']" />
                    </flux:chart.tooltip>
                </flux:chart.viewport>
            </flux:chart>
        </section>
    @endforeach

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
        <span>ESP32 → HTTP POST · unix time + t/h/p</span>
    </footer>
</div>
