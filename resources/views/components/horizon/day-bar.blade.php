<div class="hz-daybar hz-gutter" wire:ignore>
    <button class="hz-btn" data-play type="button" data-play-label="Play the day" data-pause-label="Pause" aria-pressed="false">
        <svg class="hz-i-play" viewBox="0 0 10 10" aria-hidden="true"><path d="M1.5,0.5 9.5,5 1.5,9.5Z" /></svg>
        <svg class="hz-i-pause" viewBox="0 0 10 10" aria-hidden="true"><path d="M1,0.5h3v9H1ZM6,0.5h3v9H6Z" /></svg>
        <span data-label>Play the day</span>
    </button>
    <div class="hz-track">
        <span class="hz-track-time" data-time></span>
        <div class="hz-track-rail" data-rail><input class="hz-scrub" data-scrub type="range" step="1" aria-label="Time of day in Plzeň"></div>
        <div class="hz-track-notes">
            <span>Sunrise <b data-sunrise></b></span>
            <span data-sun></span>
            <span>Sunset <b data-sunset></b></span>
        </div>
    </div>
    <button class="hz-btn" data-now type="button" hidden>Back to now</button>
</div>
