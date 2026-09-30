{{-- With the forecast: gone while it is stale, like the sky's hours. Starts folded:
     rendered hidden, so nothing flashes before Alpine runs. --}}
@if ($forecast !== null && $this->accuracyScore !== null)
    @php($score = $this->accuracyScore)
    @php($scoredHours = array_column($this->forecastAccuracy, 'hours'))
    @php($figures = array_filter([
        ['label' => 'With correction', 'figures' => $score['corrected']],
        ['label' => 'Base model', 'figures' => $score['base']],
    ], fn (array $row): bool => $row['figures'] !== null))
    <x-tile
        title="How the forecast scores"
        icon="presentation-chart-line"
        :tone="$tones['t']"
        hint="Last {{ $this::ACCURACY_DAYS }} days, hour by hour ahead"
        aria-label="Forecast accuracy"
        class="col-span-12"
        x-data="{ collapsed: true }"
    >
        <x-slot:actions>
            <x-fold-button controls="forecast-accuracy" label="Forecast accuracy" :collapsed="true" />
        </x-slot:actions>

        <div id="forecast-accuracy" class="hidden" x-bind:class="{ hidden: collapsed }">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                <p class="flex items-center gap-2 text-[13px] font-semibold text-slate-500 dark:text-slate-400">
                    <span class="size-2 rounded-full bg-amber-600 dark:bg-amber-500" aria-hidden="true"></span>
                    With correction
                </p>
                <p class="flex items-center gap-2 text-[13px] font-semibold text-slate-500 dark:text-slate-400">
                    <span class="w-3 border-t border-dashed border-slate-400" aria-hidden="true"></span>
                    Base model
                </p>
                {{-- Every hour the forecast reaches, so the control never grows under the pointer; one not scored yet is disabled. --}}
                <flux:radio.group wire:model.live="accuracyHorizon" variant="segmented" size="sm" aria-label="Hours ahead" class="ml-auto">
                    @foreach ($forecast['horizons'] as $horizon)
                        <flux:radio :value="$horizon['hours']" label="+{{ $horizon['hours'] }} h" :disabled="! in_array($horizon['hours'], $scoredHours, true)" />
                    @endforeach
                </flux:radio.group>
            </div>

            {{-- forecast-accuracy.js watches the attribute; Livewire never touches the canvas. --}}
            <div
                class="pt-2"
                data-accuracy-chart="days"
                data-accuracy-rows="{{ json_encode($score['days']) }}"
                aria-label="Temperature {{ $score['hours'] }} h ahead by day: how much smaller the miss was than the naive guess's, with the station correction and without"
                role="img"
            >
                <div wire:ignore data-accuracy-canvas class="h-40 w-full"></div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full font-mono text-xs tabular-nums">
                    <thead>
                        <tr class="text-left font-sans text-[12.5px] text-slate-500 dark:text-slate-400">
                            <th class="py-2 pr-6 font-semibold">+{{ $score['hours'] }} h</th>
                            <th class="py-2 pr-6 font-semibold">Better by</th>
                            <th class="py-2 pr-6 font-semibold">Off by · naive</th>
                            <th class="py-2 pr-6 font-semibold">In range</th>
                            <th class="py-2 pr-6 font-semibold">Range width</th>
                            <th class="py-2 font-semibold">Forecasts</th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-800 dark:text-slate-200">
                        @foreach ($figures as $row)
                            @php($shown = $row['figures'])
                            <tr class="border-t border-slate-900/5 dark:border-white/5">
                                <td class="py-1.5 pr-6 whitespace-nowrap">{{ $row['label'] }}</td>
                                <td class="py-1.5 pr-6">{{ $shown['skill'] === null ? 'n/a' : ($shown['skill'] > 0 ? '+' : '').number_format($shown['skill'], 0).' %' }}</td>
                                <td class="py-1.5 pr-6 whitespace-nowrap">
                                    @if ($shown['error'] === null)
                                        n/a
                                    @else
                                        {{ number_format($shown['error'], 1, ',', ' ') }} · {{ number_format($shown['naive'], 1, ',', ' ') }} °C
                                    @endif
                                </td>
                                <td class="py-1.5 pr-6">{{ number_format($shown['inRange'], 0) }} %</td>
                                <td class="py-1.5 pr-6">{{ number_format($shown['width'], 1, ',', ' ') }} °C</td>
                                <td class="py-1.5">{{ $shown['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="pt-2 font-mono text-xs text-slate-800 dark:text-slate-200">
                <span class="font-sans text-[12.5px] font-semibold text-slate-500 dark:text-slate-400">Rain chance · rained / dry</span>
                <span class="ml-2 tabular-nums">
                    @if ($score['rain']['count'] === 0)
                        <span class="text-slate-500 dark:text-slate-400">not listened</span>
                    @else
                        {{ $score['rain']['chanceWhenRain'] === null ? 'no rain' : number_format($score['rain']['chanceWhenRain'], 0).' %' }}
                        /
                        {{ $score['rain']['chanceWhenDry'] === null ? 'no dry spell' : number_format($score['rain']['chanceWhenDry'], 0).' %' }}
                    @endif
                </span>
            </p>

            {{-- The hour-of-day detail starts closed: rendered hidden, so nothing flashes before Alpine runs. --}}
            <div x-data="{ open: false }" class="pt-3">
                <flux:button
                    x-on:click="open = ! open"
                    variant="subtle"
                    size="xs"
                    icon="presentation-chart-line"
                    aria-expanded="false"
                    x-bind:aria-expanded="open ? 'true' : 'false'"
                    aria-controls="forecast-accuracy-hours"
                    x-bind:class="{ 'text-slate-800! dark:text-white!': open }"
                >By hour of the day</flux:button>

                {{-- Hidden, not removed: the chart keeps its instance and resizes once it has a size. --}}
                <div id="forecast-accuracy-hours" class="hidden pt-2" x-bind:class="{ hidden: ! open }">
                    <div
                        data-accuracy-chart="hours"
                        data-accuracy-rows="{{ json_encode($score['byHour']) }}"
                        aria-label="Temperature {{ $score['hours'] }} h ahead, measured minus forecast by hour of the day"
                        role="img"
                    >
                        <div wire:ignore data-accuracy-canvas class="h-24 w-full"></div>
                    </div>
                </div>
            </div>

            <p class="pt-3 text-[12.5px] leading-relaxed text-slate-500 dark:text-slate-400">
                Better by: how much smaller the forecast's miss was than the naive guess's - the temperature at the time of the forecast, kept for the hours ahead.
                Above zero the forecast beats the guess, below zero it does worse.
                Base model: the forecast as trained on ČHMÚ records, before the correction this station's own misses teach it;
                the gap between the two lines is what the correction has learnt.
                In range: a good range holds eight readings in ten, so 80 % is on target and 100 % means it was drawn too wide.
                Rain as the INMP441 microphone heard it: the mean chance given when it rained and when it stayed dry. Drizzle is not heard.
                By hour of the day: measured minus the middle of the forecast shown, above zero warmer than forecast.
            </p>
        </div>
    </x-tile>
@endif
