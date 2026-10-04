@php($page ??= 'overview')
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-screen focus:px-3 focus:py-2">Skip to content</a>
<header class="border-b border-line">
    <div class="page-wrap flex flex-wrap items-center gap-x-10 gap-y-2 pt-4 pb-3 sm:py-5">
        <a href="{{ route('overview', $this->sensorQuery()) }}" class="mr-auto flex items-center gap-3 text-ink no-underline" aria-label="Balcony Station, overview">
            <svg width="34" height="20" viewBox="0 0 34 20" aria-hidden="true" class="shrink-0">
                <path d="M1 10 Q 5.5 0 10 10 T 19 10 T 28 10" fill="none" stroke="var(--ink)" stroke-width="1.8" stroke-linecap="round" />
                <circle cx="29.5" cy="10" r="2.6" fill="var(--ch2)" />
            </svg>
            <span class="font-display text-[19px] font-semibold tracking-[-0.01em]">Balcony Station</span>
            <span class="hidden font-mono text-[13px] text-ink-3 sm:inline">Plzeň</span>
        </a>
        <div class="flex items-center gap-5 sm:order-last">
            @if ($this->hasSensorChoice)
                <label class="flex items-center gap-2 font-mono text-[13px] text-ink-3">
                    <span class="hidden sm:inline">Sensor</span>
                    <select
                        wire:model.live="sensor"
                        class="h-10 cursor-pointer rounded-md border border-line-2 bg-screen px-2.5 font-mono text-[14px] text-ink"
                        aria-label="Choose a sensor"
                    >
                        @foreach ($this->sensors as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if ($this->measuredAt !== null && $page !== 'overview')
                <p class="num m-0 flex items-center gap-2 font-mono text-[13px] text-ink-3" title="Newest reading from the station">
                    <span @class(['electron', 'text-ink' => ! $this->isSilent, 'text-ink-3' => $this->isSilent]) aria-hidden="true"></span>
                    <span>{{ $this->isSilent ? 'STOP' : 'RUN' }}</span>
                    <span class="hidden text-ink-2 md:inline">{{ $this->measuredAt }}</span>
                </p>
            @endif
            <button
                type="button"
                x-data
                x-on:click="$flux.dark = ! $flux.dark"
                class="grid size-10 cursor-pointer place-items-center rounded-md border border-line-2 bg-transparent text-ink-2 hover:text-ink"
                aria-label="Toggle dark mode"
            >
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><circle cx="9" cy="9" r="7.2" fill="none" stroke="currentColor" stroke-width="1.6" /><path d="M9 1.8 A7.2 7.2 0 0 1 9 16.2 Z" fill="currentColor" /></svg>
            </button>
        </div>
        <nav aria-label="Main" class="w-full sm:w-auto">
            <ul class="m-0 flex list-none gap-7 p-0 text-[16px] font-medium">
                <li><a class="navlink" href="{{ route('overview', $this->sensorQuery()) }}" @if ($page === 'overview') aria-current="page" @endif>Overview</a></li>
                <li><a class="navlink" href="{{ route('charts', $this->sensorQuery()) }}" @if ($page === 'charts') aria-current="page" @endif>Charts</a></li>
                <li><a class="navlink" href="{{ route('forecast', $this->sensorQuery()) }}" @if ($page === 'forecast') aria-current="page" @endif>Forecast</a></li>
            </ul>
        </nav>
    </div>
</header>
