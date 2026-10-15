<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="about-h">
    <div class="grid gap-x-20 gap-y-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <div class="max-w-[62ch]">
            <h2 id="about-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">Measuring the air beyond the shield</h2>
            <p class="m-0 mt-5 text-[18px] leading-[1.65] text-ink-2">How closely can this balcony station estimate outdoor air temperature after correcting for heating inside its radiation shield?</p>
        </div>
        <div class="max-w-[62ch] lg:pt-2">
            <p class="m-0 text-[16px] leading-[1.7] text-ink-2">An ESP32 on an east-facing balcony in Plzeň records temperature and humidity with an SHT4x in a radiation shield, and pressure with a BMP280 indoors. It samples every 30 seconds and sends a ten-minute summary. Light and sound are recorded alongside these measurements.</p>
            <p class="m-0 mt-5 text-[16px] leading-[1.7] text-ink-2">The graphs show the original sensor readings alongside a shade estimate from a correction calibrated for this shield and its position on the balcony. The estimate appears only when a valid calibration and enough light history are available.</p>
            <p class="m-0 mt-5 text-[16px] leading-[1.7] text-ink-2">ČHMÚ Plzeň-Mikulka, about 3.5 km away, is the professional temperature reference for developing and evaluating the correction. Differences in location and microclimate remain part of that comparison.</p>
            <p class="m-0 mt-5"><a class="link-ink text-[16px]" href="{{ route('charts', $this->sensorQuery()) }}">Explore the measurement history</a></p>
        </div>
    </div>

    @include('livewire.overview.wiring')
</section>
