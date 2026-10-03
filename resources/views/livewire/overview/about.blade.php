<section class="page-wrap mt-20 sm:mt-24" aria-labelledby="about-h">
    <div class="grid gap-x-20 gap-y-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <div class="max-w-[62ch]">
            <h2 id="about-h" class="m-0 font-display text-[26px] leading-tight font-semibold tracking-[-0.01em] sm:text-[30px]">One balcony, one question</h2>
            <p class="m-0 mt-5 text-[18px] leading-[1.65] text-ink-2">Can a single station forecast its own next six hours better than the naive guess that nothing changes?</p>
        </div>
        <div class="max-w-[62ch] lg:pt-2">
            <p class="m-0 text-[16px] leading-[1.7] text-ink-2">An ESP32 on an east-facing balcony in Plzeň measures temperature, humidity, light and sound every 30 seconds, and air pressure indoors. Every ten minutes it sends a summary to the server. A model trained on professional ČHMÚ stations then forecasts the next six hours from nothing but this station's own readings, and every forecast is checked against what actually happened.</p>
            <p class="m-0 mt-5"><a class="link-ink text-[16px]" href="{{ route('forecast', $this->sensorQuery()) }}">How the forecast works</a> <span class="text-ink-3">·</span> <a class="link-ink text-[16px]" href="{{ route('charts', $this->sensorQuery()) }}">All channels in detail</a></p>
        </div>
    </div>

    @include('livewire.overview.wiring')
</section>
