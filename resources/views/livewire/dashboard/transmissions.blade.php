@if ($this->recentTransmissions !== [])
    @php($transmissionCount = count($this->recentTransmissions))
    <x-tile
        title="Last transmission"
        icon="code-xml"
        aria-label="Last transmissions"
        :class="\Illuminate\Support\Arr::toCssClasses(['col-span-12', 'lg:col-span-7' => $hasReport])"
        x-data="{ all: false }"
    >
        <x-slot:actions>
            {{-- Counts what is on screen: folded, that is the newest one alone. --}}
            <p class="text-[13px] font-medium text-slate-500 dark:text-slate-400">
                <span x-bind:class="{ hidden: all }">Last measurement · when it arrived</span>
                @if ($transmissionCount > 1)
                    <span class="hidden" x-bind:class="{ hidden: ! all }">Last {{ $transmissionCount }} measurements · when they arrived</span>
                @endif
            </p>
            {{-- Folded, the newest transmission still shows; only the older ones go. Nothing to unfold with one. --}}
            <flux:button
                x-on:click="all = ! all"
                variant="subtle"
                size="xs"
                icon="chevron-down"
                aria-expanded="false"
                x-bind:aria-expanded="all ? 'true' : 'false'"
                aria-controls="transmissions"
                aria-label="Show all transmissions"
                x-bind:aria-label="all ? 'Show the last transmission only' : 'Show all transmissions'"
                x-bind:class="{ '[&_svg]:rotate-180': all }"
                :disabled="$transmissionCount < 2"
            />
        </x-slot:actions>

        <p class="mb-2 font-mono text-xs text-slate-500 dark:text-slate-400">
            POST /api/v1/measurement · 0,01 °C · 0,01 % · Pa · UTC unix · samples
        </p>

        <div id="transmissions" class="grid gap-2">
            @foreach ($this->recentTransmissions as $packet)
                <div
                    @class([
                        'grid gap-x-8 gap-y-2 rounded-2xl bg-slate-900/[0.04] px-4 py-3 dark:bg-black/25',
                        'hidden' => ! $loop->first,
                    ])
                    @unless ($loop->first)
                        x-bind:class="{ hidden: ! all }"
                    @endunless
                >
                    {{-- The blob as stored, one field per line: a V2 packet on
                         one line outruns a desktop. Coloured by the key's first word.
                         V3's "noise" object stays on its line as JSON. --}}
                    <p class="overflow-x-auto font-mono text-xs leading-relaxed text-slate-500 tabular-nums dark:text-slate-400">
                        <span class="block text-slate-400 dark:text-slate-600">{</span>
                        <span class="block pl-4">"timestamp": <span class="text-slate-700 dark:text-slate-300">{{ $packet['timestamp'] }}</span><span class="text-slate-400 dark:text-slate-600">,</span></span>
                        @foreach ($packet['packet'] as $field => $value)
                            @php($accent = match (strtok($field, '_')) {
                                'temperature' => 'text-amber-600',
                                'humidity' => 'text-cyan-600',
                                'pressure' => 'text-violet-600 dark:text-violet-500',
                                default => 'text-zinc-700 dark:text-zinc-300',
                            })
                            <span class="block pl-4">"{{ $field }}": <span class="{{ $accent }}{{ is_array($value) ? ' break-all' : '' }}">{{ is_array($value) ? json_encode($value) : $value }}</span>@unless ($loop->last)<span class="text-slate-400 dark:text-slate-600">,</span>@endunless</span>
                        @endforeach
                        <span class="block text-slate-400 dark:text-slate-600">}</span>
                    </p>

                    <p class="flex flex-wrap gap-x-4 border-t border-slate-900/5 pt-2 font-mono text-xs text-slate-500 tabular-nums dark:border-white/5 dark:text-slate-400">
                        <span>{{ $packet['at'] }}</span>
                        <span><span class="text-slate-800 dark:text-slate-200">{{ number_format($packet['t'], 2, ',', ' ') }}</span> °C</span>
                        <span><span class="text-slate-800 dark:text-slate-200">{{ number_format($packet['h'], 2, ',', ' ') }}</span> %</span>
                        <span><span class="text-slate-800 dark:text-slate-200">{{ number_format($packet['p'], 1, ',', ' ') }}</span> hPa MSL</span>
                        <span class="hidden md:inline">{{ $packet['ago'] }}</span>
                    </p>
                </div>
            @endforeach
        </div>
    </x-tile>
@endif
