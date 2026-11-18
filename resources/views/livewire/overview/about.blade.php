@php($temperature = $this->metrics['t'] ?? null)
@php($celsius = \App\Enums\Channel::Temperature)
<x-horizon.section aria-labelledby="about-h">
    <div class="hz-story">
        <div class="hz-prose">
            <x-horizon.heading id="about-h">Measuring the air beyond the shield</x-horizon.heading>
            <p class="hz-question">How closely can this balcony station estimate outdoor air temperature after correcting for heating inside its radiation shield?</p>
            <p>An ESP32 on an east-facing balcony in Plzeň records temperature and humidity with an SHT4x in a radiation shield, and pressure with a BMP280 indoors. It samples every 30 seconds and sends a ten-minute summary. Light and sound are recorded alongside these measurements.</p>
            <p>Every sunny morning the sun warms the shield and the sensor with it. The shade estimate subtracts that heating with a correction calibrated for this shield and its position on the balcony. It appears only when a valid calibration and enough light history are available.</p>
            <a class="hz-link" href="{{ route('charts', $this->sensorQuery()) }}">Explore the measurement history</a>
        </div>

        @if ($temperature !== null)
            @php($traceLow = $this->temperatureTrace->low(min($temperature['trace'])))
            @php($traceHigh = max($temperature['trace']))
            @php($trace = \App\ValueObject\Trace::spanning($temperature['trace'], $traceLow, $traceHigh))
            @php($newest = $trace->points[array_key_last($trace->points)])
            @php($shadePath = $this->temperatureTrace->line($traceLow, $traceHigh))
            @php($ticks = \App\ValueObject\Trace::ticks($traceLow, $traceHigh, fn (float $value): float => \App\ValueObject\Trace::levelOf($value, $traceLow, $traceHigh), [0.1, 0.2, 0.5, 1.0, 2.0, 5.0, 10.0]))
            <figure class="hz-card m-0">
                <div class="hz-card-head">
                    <h3>Last 24 hours</h3>
                    <div class="hz-legend">
                        <span><i style="background: var(--ref)"></i>Shade estimate</span>
                        <span><i style="background: var(--ch1)"></i>Sensor reading</span>
                        <span>min {{ $celsius->format($temperature['dayMin']) }} · max {{ $celsius->format($temperature['dayMax']) }} {{ $celsius->unit() }}</span>
                    </div>
                </div>
                <div class="hz-plot" role="img" aria-label="Temperature over the last 24 hours, between {{ $celsius->format($temperature['dayMin']) }} and {{ $celsius->format($temperature['dayMax']) }} {{ $celsius->unit() }}, now {{ $celsius->format($temperature['now']) }} {{ $celsius->unit() }}.">
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        @foreach ($ticks as $tick)
                            <line class="hz-plot-grid" x1="0" x2="100" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" />
                        @endforeach
                        <path d="{{ $trace->line() }}" style="stroke: var(--ch1); stroke-width: 2px" />
                        @if ($shadePath !== '')
                            <path data-shade-trace d="{{ $shadePath }}" style="stroke: var(--ref); stroke-width: 2.5px" />
                        @endif
                    </svg>
                    @foreach ($ticks as $tick)
                        <span class="hz-plot-label" style="top: {{ $tick['y'] }}%">{{ \App\ValueObject\Figure::format($tick['value'], 1) }}</span>
                    @endforeach
                    <span class="hz-plot-now" style="left: {{ $newest['x'] }}%; top: {{ $newest['y'] }}%; background: var(--ch1)" aria-hidden="true"></span>
                </div>
                <div class="hz-plot-axis"><span>24 h ago</span><span>now</span></div>
                <p class="hz-card-note">Measured inside the radiation shield, {{ $celsius->unit() }}. The shade estimate subtracts the modelled heating of the shield. <a href="{{ route('comparison', $this->sensorQuery()) }}" class="link-ink">Compare with the shade reference</a>.</p>
            </figure>
        @endif
    </div>

    @include('livewire.overview.wiring')
</x-horizon.section>
