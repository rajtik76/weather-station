<footer class="mt-24 border-t border-line">
    <div class="page-wrap grid gap-6 py-10 text-[15px] text-ink-3 md:grid-cols-[1fr_auto] md:items-end">
        <div class="max-w-[60ch]">
            <p class="m-0 text-ink-2">A hobby weather station on a balcony in Plzeň, Czech Republic.</p>
            <p class="m-0 mt-1">Forecast model trained on open data from ČHMÚ (Czech Hydrometeorological Institute), licensed CC BY 4.0. Source: ČHMÚ.</p>
        </div>
        <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 font-mono text-[13px]">
            <span>© {{ $this->currentYear }} <a class="link-ink" href="https://rajtik.com">Vladislav Rajtmajer</a></span>
            <a class="link-ink" href="https://github.com/rajtik76/weather-station">Source on GitHub</a>
        </p>
    </div>
</footer>
