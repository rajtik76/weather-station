{{-- ECharts watches data-accuracy-rows (forecast-accuracy.js); Livewire never touches the canvas. --}}
@php($race = $this->race)
@php($scored = array_filter($race->days, fn (\App\ValueObject\RaceDay $day): bool => $day->points !== []))
@php($swatches = [
    'correction' => \App\Enums\Channel::Temperature->backgroundClass(),
    'light-v6' => \App\Enums\Channel::Light->backgroundClass(),
    'light-v5' => \App\Enums\Channel::Pressure->backgroundClass(),
])
@php($dashed = '<svg width="16" height="2" aria-hidden="true"><line x1="0" y1="1" x2="16" y2="1" stroke="var(--ref)" stroke-width="2" stroke-dasharray="3 3" /></svg>')
@php($opening = \App\Enums\RaceBlock::Morning)
<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="race-h" x-data="{ block: '{{ $opening->value }}' }">
    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
        <div class="max-w-[60ch]">
            <h2 id="race-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Model race</h2>
            <p class="m-0 mt-3 text-[16px] leading-relaxed text-ink-2">Every model forecasts every ten minutes. For each day it gets points, the mean miss of its median in °C, separately for three parts of the day. The lowest total over the last {{ \App\Queries\CachedModelRace::RACE_DAYS }} days leads, and the shown temperature for that part of the day comes from the leader. The standings are settled after midnight and hold for the whole day.</p>
        </div>
        <div class="seg" role="group" aria-label="Part of the day">
            @foreach (\App\Enums\RaceBlock::cases() as $block)
                <button
                    type="button"
                    x-on:click="block = '{{ $block->value }}'; $dispatch('race-block', { block: '{{ $block->value }}' })"
                    x-bind:aria-pressed="(block === '{{ $block->value }}').toString()"
                >{{ $block->label() }}</button>
            @endforeach
        </div>
    </div>

    @if ($scored === [])
        <p class="m-0 mt-8 rounded-[10px] border border-line bg-screen px-6 py-10 text-ink-2">No day scored yet: the race starts once every model has forecast a whole day.</p>
    @else
        <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <div class="min-w-0">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
                    <h3 class="m-0 font-display text-[22px] leading-tight font-semibold sm:text-[24px]">Points by day</h3>
                    <p class="m-0 flex flex-wrap items-center gap-4 font-mono text-[13px] text-ink-3">
                        @foreach (\App\ValueObject\RaceEntrants::NAMES as $name)
                            <span class="flex items-center gap-2">
                                @if (isset($swatches[$name]))
                                    <span class="swatch {{ $swatches[$name] }}"></span>
                                @else
                                    {!! $dashed !!}
                                @endif
                                {{ $name }}
                            </span>
                        @endforeach
                    </p>
                </div>
                <div
                    class="rounded-[10px] border border-line bg-screen p-3"
                    data-accuracy-chart="race"
                    data-accuracy-rows="{{ json_encode(['entrants' => \App\ValueObject\RaceEntrants::NAMES, 'days' => $race->days]) }}"
                    data-accuracy-experiment=""
                    role="img"
                    aria-label="Points of every model by day over the last {{ \App\Queries\CachedModelRace::RACE_DAYS }} days, lower is better"
                >
                    <div wire:ignore data-accuracy-canvas class="h-[260px] w-full"></div>
                </div>
            </div>
            <div class="min-w-0">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
                    <h3 class="m-0 font-display text-[22px] leading-tight font-semibold sm:text-[24px]">Standings</h3>
                    <p class="m-0 font-mono text-[13px] text-ink-3">{{ \App\Queries\CachedModelRace::RACE_DAYS }} days · lowest wins</p>
                </div>
                @foreach (\App\Enums\RaceBlock::cases() as $block)
                    @php($table = $race->table($block))
                    <div x-show="block === '{{ $block->value }}'" @if ($block !== $opening) x-cloak @endif data-race-block="{{ $block->value }}">
                        <div class="overflow-x-auto rounded-[10px] border border-line bg-screen px-5 pt-5 pb-2" tabindex="0" role="region" aria-label="Standings, {{ $block->label() }}">
                            <table class="mtable">
                                <thead>
                                    <tr>
                                        <th scope="col">Model</th>
                                        <th scope="col">Points</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($table as $standing)
                                        <tr @class(['font-semibold' => $loop->first && $standing['total'] !== null])>
                                            <td>{{ $standing['name'] }}@if ($loop->first && $standing['total'] !== null) <span class="font-normal text-ink-3">· shown</span>@endif</td>
                                            <td>{{ $standing['total'] === null ? 'n/a' : \App\ValueObject\Figure::format($standing['total'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="m-0 mt-3 text-[15px] leading-relaxed text-ink-3">{{ $block->label() }}: forecasts for {{ $block->hours() }}. Points are summed over the days every model forecast.</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
