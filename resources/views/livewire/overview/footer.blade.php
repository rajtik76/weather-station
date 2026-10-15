<footer class="mt-24 border-t border-line">
    <div class="page-wrap grid gap-6 py-10 text-[15px] text-ink-3 md:grid-cols-[1fr_auto] md:items-end">
        <div class="max-w-[60ch]">
            <p class="m-0 text-ink-2">A balcony weather station in Plzeň, working towards a more accurate estimate of outdoor air temperature.</p>
            <p class="m-0 mt-1">Temperature reference for the correction: ČHMÚ Plzeň-Mikulka. Open data from the Czech Hydrometeorological Institute, licensed CC BY 4.0.</p>
        </div>
        <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 font-mono text-[13px]">
            <span>© {{ $this->currentYear }} <a class="link-ink" href="https://rajtik.com">Vladislav Rajtmajer</a></span>
            <a class="link-ink" href="https://github.com/rajtik76/weather-station">Source on GitHub</a>
        </p>
    </div>
</footer>
