<footer class="mt-6 flex flex-wrap items-center justify-between gap-2 px-1.5 text-[13px] text-slate-500 dark:text-slate-400">
    <span>{{ number_format($this->recordCount, 0, ',', ' ') }} records</span>
    <span>
        &copy; {{ $this->currentYear }} Vladislav Rajtmajer ·
        <a
            href="https://github.com/rajtik76"
            target="_blank"
            rel="noopener noreferrer"
            class="rounded-sm underline decoration-slate-300 underline-offset-4 hover:text-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 dark:decoration-slate-600 dark:hover:text-slate-300 dark:focus-visible:outline-slate-100"
        >GitHub</a>
    </span>
</footer>
