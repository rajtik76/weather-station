@props(['year'])

<footer class="hz-footer">
    <div class="hz-wrap hz-gutter">
        {{ $slot }}
        <p class="hz-sign">Balcony Station, an east-facing balcony in Plzeň.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
            <div class="max-w-[60ch]">
                <p class="m-0 text-ink-2">A balcony weather station in Plzeň, working towards a more accurate estimate of outdoor air temperature.</p>
                <p class="m-0 mt-1">Temperature reference for the correction: ČHMÚ Plzeň-Mikulka. Open data from the Czech Hydrometeorological Institute, licensed CC BY 4.0.</p>
            </div>
            <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-[14px]">
                <span>© {{ $year }} <a class="link-ink" href="https://rajtik.com">Vladislav Rajtmajer</a></span>
                <a class="link-ink" href="https://github.com/rajtik76/weather-station">Source on GitHub</a>
            </p>
        </div>
    </div>
</footer>
