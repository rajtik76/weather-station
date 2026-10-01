{{-- SMIL, hidden under reduced motion. --}}
@php($report = $this->stationReport)
<figure class="m-0 mt-12">
    <div class="overflow-x-auto rounded-[10px] border border-line">
        <svg class="sch block w-full min-w-[860px]" viewBox="0 0 1000 440" role="img" aria-labelledby="sch-title">
          <title id="sch-title">How the station is wired: SHT4x, VEML7700 and BMP280 on I2C, INMP441 on I2S, all wired to the ESP32-WROOM-32, which uploads over HTTPS to the Laravel API, PostgreSQL, the forecast service and this page.</title>
          <defs>
            <pattern id="sch-minor" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M10 0H0V10" fill="none" class="g-minor" stroke-width="0.6"/></pattern>
            <pattern id="sch-major" width="50" height="50" patternUnits="userSpaceOnUse"><rect width="50" height="50" fill="url(#sch-minor)"/><path d="M50 0H0V50" fill="none" class="g-major" stroke-width="0.8"/></pattern>
            <marker id="sch-arrow" viewBox="0 0 8 8" refX="7.5" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0.5L8 4L0 7.5z" class="arrowhead"/></marker>
          </defs>
          <rect width="1000" height="440" class="sheet"/>
          <rect width="1000" height="440" fill="url(#sch-major)"/>

          <!-- Outdoor: radiation shield -->
          <rect x="20" y="40" width="280" height="370" class="bound"/>
          <text x="20" y="28" class="t-note">RADIATION SHIELD · OUTDOOR</text>

          <rect x="44" y="70" width="180" height="60" class="box"/>
          <text x="58" y="96" class="t-part">SHT4x</text>
          <text x="58" y="117" class="t-fn">Temperature, humidity</text>
          <text x="212" y="96" text-anchor="end" class="t-ch"><tspan class="s-ch1">CH1</tspan> <tspan class="s-ch2">CH2</tspan></text>
          <text x="212" y="117" class="t-pin" text-anchor="end">0x44</text>

          <rect x="44" y="160" width="180" height="60" class="box"/>
          <text x="58" y="186" class="t-part">VEML7700</text>
          <text x="58" y="207" class="t-fn">Light</text>
          <text x="212" y="186" text-anchor="end" class="t-ch s-aux">AUX</text>
          <text x="212" y="207" class="t-pin" text-anchor="end">0x10</text>

          <rect x="44" y="300" width="180" height="60" class="box"/>
          <text x="58" y="326" class="t-part">INMP441</text>
          <text x="58" y="347" class="t-fn">MEMS microphone</text>
          <text x="212" y="326" text-anchor="end" class="t-ch s-ch4">CH4</text>
          <path d="M168 345 q4 -8 8 0 t8 0 t8 0 t8 0 t8 0" class="sine s-ch4"/>

          <!-- Indoor: base board -->
          <rect x="320" y="40" width="420" height="370" class="bound"/>
          <text x="320" y="28" class="t-note">BASE BOARD · INDOOR</text>

          <rect x="350" y="205" width="160" height="60" class="box"/>
          <text x="364" y="231" class="t-part">BMP280</text>
          <text x="364" y="252" class="t-fn">Pressure</text>
          <text x="498" y="231" text-anchor="end" class="t-ch s-ch3">CH3</text>
          <text x="498" y="252" class="t-pin" text-anchor="end">0x76</text>

          <rect x="540" y="70" width="180" height="310" class="box box-mcu"/>
          <text x="554" y="92" class="t-note">U1</text>
          <text x="554" y="114" class="t-part">ESP32-WROOM-32</text>
          <text x="554" y="133" class="t-fn">{{ $report['board'] ?? 'ESP32_DEV' }}{{ $report === null ? '' : ' · fw '.$report['firmware'] }}</text>
          <text x="554" y="169" class="t-pin">I2C</text>
          <text x="554" y="334" class="t-pin">I2S</text>
          <text x="708" y="169" class="t-pin" text-anchor="end">Wi-Fi</text>
          <text x="554" y="236" class="t-note">sample every 30 s</text>
          <text x="554" y="256" class="t-note">fold into 10 min</text>
          <path d="M554 280 q5 -9 10 0 t10 0 t10 0 t10 0 t10 0 t10 0 t10 0 t10 0" class="sine s-ch1"/>

          <!-- Buses -->
          <path d="M224 100H262M224 190H262" class="sch-wire"/>
          <path d="M262 100V190M262 145H540" class="bus"/>
          <path d="M430 145V205" class="sch-wire"/>
          <circle cx="262" cy="145" r="4" class="junction"/>
          <circle cx="430" cy="145" r="4" class="junction"/>
          <text x="276" y="136" class="t-net">I2C · SDA 21 · SCL 22</text>
          <path d="M224 330H540" class="bus"/>
          <text x="240" y="321" class="t-net">I2S · SCK 26 · WS 25 · SD 33</text>

          <!-- Server -->
          <rect x="780" y="40" width="200" height="370" class="bound"/>
          <text x="780" y="28" class="t-note">SERVER</text>
          <path d="M720 165H798" class="sch-wire" marker-end="url(#sch-arrow)"/>
          <text x="728" y="156" class="t-net">HTTPS</text>

          <rect x="800" y="140" width="160" height="50" class="box"/>
          <text x="814" y="162" class="t-part">Laravel API</text>
          <text x="814" y="180" class="t-fn">validates, stores</text>

          <rect x="800" y="220" width="160" height="50" class="box"/>
          <text x="814" y="242" class="t-part">PostgreSQL</text>
          <text x="814" y="260" class="t-fn">readings, forecasts</text>

          <rect x="800" y="300" width="160" height="50" class="box"/>
          <text x="814" y="322" class="t-part">Forecast</text>
          <text x="814" y="340" class="t-fn">Python, after each upload</text>

          <path d="M880 190V218" class="sch-wire" marker-end="url(#sch-arrow)"/>
          <path d="M880 272V298" class="sch-wire" marker-start="url(#sch-arrow)" marker-end="url(#sch-arrow)"/>

          <rect x="800" y="70" width="160" height="44" class="box box-soft"/>
          <text x="814" y="90" class="t-part">Dashboard</text>
          <text x="814" y="106" class="t-fn">Livewire, this page</text>
          <path d="M960 245H970V92H962" class="sch-wire" marker-end="url(#sch-arrow)"/>

          <!-- Electrons: one bead per reading; I2C beads share one speed and are staggered, one bead leaves the ESP32 per upload -->
          <g class="bead b-ch1"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion begin="-0.2s" dur="3s" repeatCount="indefinite" path="M224 100H262V145H540"/></g>
          <g class="bead b-ch2"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion dur="3s" repeatCount="indefinite" path="M224 100H262V145H540"/></g>
          <g class="bead b-aux"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion begin="-1.6s" dur="3s" repeatCount="indefinite" path="M224 190H262V145H540"/></g>
          <g class="bead b-ch3"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion begin="-2.31s" dur="3s" repeatCount="indefinite" calcMode="linear" keyPoints="0;1;1" keyTimes="0;0.471;1" path="M430 205V145H540"/><animate attributeName="opacity" begin="-2.31s" dur="3s" repeatCount="indefinite" calcMode="discrete" values="1;0" keyTimes="0;0.471"/></g>
          <g class="bead b-ch4"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion dur="2.7s" repeatCount="indefinite" path="M224 330H540"/></g>
          <g class="bead b-ch4"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion begin="-1.35s" dur="2.7s" repeatCount="indefinite" path="M224 330H540"/></g>
          <g class="bead b-ink"><circle r="9" class="bead-halo"/><circle r="5" class="bead-core"/><animateMotion dur="1.8s" repeatCount="indefinite" path="M720 165H796"/></g>
        </svg>
    </div>
    <figcaption class="mt-3 flex flex-wrap justify-between gap-x-6 gap-y-1">
        <span class="label-mono">Signal path · each dot is data on the move, in its channel's colour</span>
        <span class="label-mono">I2C · I2S · HTTPS</span>
    </figcaption>
</figure>
