{{-- Outside production only: previews a holiday, rain or wind on the sky. The page data stays real. --}}
<div class="hz-mock" wire:ignore>
    <p>Preview controls, not shown in production</p>
    <label>Holiday <select class="hz-select" data-holiday-pick><option value="">Today's date</option></select></label>
    <label>Rain
        <select class="hz-select" data-rain-pick>
            <option value="detected">As detected</option>
            <option value="none">None</option>
            <option value="light">Light rain</option>
            <option value="heavy">Heavy rain</option>
        </select>
    </label>
    <label>Wind
        <select class="hz-select" data-wind-pick>
            <option value="calm">Calm</option>
            <option value="breeze">Breeze</option>
            <option value="strong">Strong wind</option>
        </select>
    </label>
    <p class="hz-mock-hint" data-holiday-hint hidden></p>
</div>
