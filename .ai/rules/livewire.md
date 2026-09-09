---
paths:
    - "app/Livewire/**"
    - "resources/views/livewire/**"
    - "resources/js/**"
---

# Livewire

## Charts are ECharts, not Flux

Flux's chart is a drawing primitive, not a time-series library: no zoom, no brush, no navigator, no cursor sync, its date formatters force UTC and cannot be overridden, it reads element attributes once on connect, and a `ui-chart` only ever stamps the first `<flux:chart.svg>` inside it. Every one of those needed a workaround; the whole set is what ECharts gives as configuration.

Flux still owns the rest of the UI - buttons, the segmented range picker, layout, dark mode. Only the charts moved.

Three ECharts instances joined with `echarts.connect()`, one per channel, so the HTML headers can sit between them. Each draws one series but its tooltip reads every channel out of the shared rows.

## The chart payload is wall-clock, not instants

Rows are `[wall-clock ms, °C, %, hPa, real epoch seconds]`. The first element has the Czech UTC offset folded in and ECharts runs with `useUTC: true`, so the axis and tooltip read Czech local time whatever clock the viewer is on, and ticks land on local midnight instead of an hour off it.

That first element is therefore not an instant. Never measure it against `now()` and never convert it a second time - anything formatting it must do so as UTC. The fifth element is the real epoch, and that is what a zoom hands back to `zoomTo()`.

Storage stays UTC: the firmware sends `time(nullptr)` and `config/app.php` keeps `'timezone' => 'UTC'`. Only presentation shifts.

## The window is from/to, and zooming re-queries

`range` is only a preset that seeds the window; `from`/`to` are real epochs and override it. Stepping by whole preset-sized units was the earlier design and made short windows unreachable without hammering an arrow.

A drag-selection sends real epochs to `zoomTo()`, which re-queries. That round trip is the point: thinning follows the span actually on screen (`ChartRange::forSpan()`), so zooming into a thinned year returns the readings the year view skipped.

`normaliseWindow()` sorts, clamps to the present and enforces a minimum span - both ends arrive from the query string and from drags that may have been stray clicks.

## Components are class-based, not single-file

The dashboard was converted off the Livewire 4 single-file format because the PHP block kept outgrowing the template above it. Class in `app/Livewire`, view in `resources/views/livewire`, wired by class name in `routes/web.php`. `php artisan livewire:convert` only moves between SFC and MFC, so a class-based conversion is manual.

The layout resolves through Livewire's default `component_layout => 'layouts::app'`, which is why `resources/views/layouts/app.blade.php` needs no wiring of its own.

## Canvas regions carry wire:ignore; data arrives by attribute

ECharts owns its DOM, so each channel's container is `wire:ignore` and a morph must never reach it. New data reaches the canvas through a separate `data-chart-rows` element that Livewire does re-render; `station-charts.js` watches that attribute with a MutationObserver and calls `setOption`.

Controls that appear conditionally in the right-aligned toolbar shift everything beside them. Render them always and disable them instead - a button that pops in on first use slides the neighbouring controls out from under the pointer mid-click.

## The dashboard polls, and a zoomed window freezes the charts

`wire:poll.60s` on the dashboard root refreshes the page. No broadcasting: the station uploads once per ten minutes, so Reverb or Pusher would be a websocket server and a dependency for nothing.

Nothing suppresses the poll while a reader is zoomed - the freeze falls out of the design. A zoomed window is a pair of fixed epochs, so the re-query returns the same readings and `data-chart-rows` morphs back identical; an unchanged attribute produces no MutationObserver record, so the canvases are never repainted under the reader.

`station-charts.js` watches `data-navigator-rows` too, because the navigator always spans the whole record and does grow while zoomed. Since one mount serves both, `render()` repaints the channels only when the chart payload actually changed (`painted`), or when `mount(true)` forces it - a theme switch or a Livewire navigation. Do not call `mount` straight from an observer or event listener: the first argument would land in `force`.

## Pressure is stored raw and reduced to sea level only for display

The BME280 sends station pressure, and the record keeps it verbatim - protocol V1 does not change. Everything shown on the dashboard goes through `SeaLevelPressure::reduce()` with `Dashboard::ALTITUDE_METRES` (345 m, Plzeň-Slovany), so a corrected height never means rewriting stored rows.

The reduction is hypsometric and uses the reading's own temperature, not the standard atmosphere's fixed 15 °C: at 345 m the difference between a frost and a heatwave is about 6 hPa, and the sensor already measures it.

Consequence for the payload tail: the raw JSON prints station pressure in Pa while the converted column beside it is sea-level hPa. They are meant to differ by ~40 hPa - that is not a bug.

## Thinning by the phase of the epoch misses a drifting station

The station stamps an upload when it wakes, not on the slot, so its timestamps sit a couple of minutes off every multiple of STEP_SECONDS. `whereRaw('timestamp % ? < ?')` only lands on a row while that drift stays under the step, and a record shorter than one bucket holds no such row at all - which is what emptied the navigator on a production database a few hours old.

`overview()` therefore groups (`MIN(timestamp)` per `timestamp / OVERVIEW_BUCKET_SECONDS`) instead, and skips thinning entirely below OVERVIEW_UNTHINNED_ROWS. The window thinning in `readings()` still uses the modulo: its buckets are step-sized, so the current ~2 min drift is harmless there, but a larger one would silently empty the chart the same way.
