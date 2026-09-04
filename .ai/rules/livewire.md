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
