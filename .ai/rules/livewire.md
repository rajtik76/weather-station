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

Rows are `[wall-clock ms, °C, %, hPa, dew point °C, epoch seconds]` - on the strips the epoch is the bucket's slot, on the navigator the reading's own stamp. Strip rows go on with the extremes of the samples in the bucket, `°C min, °C max, % min, % max, hPa min, hPa max`; navigator rows stop at the epoch. The first element has the Czech UTC offset folded in and ECharts runs with `useUTC: true`, so the axis and tooltip read Czech local time whatever clock the viewer is on, and ticks land on local midnight instead of an hour off it.

That first element is therefore not an instant. Never measure it against `now()` and never convert it a second time - anything formatting it must do so as UTC. The sixth element is the real epoch, and that is what a zoom hands back to `zoomTo()`. `station-charts.js` reads columns through its `COLUMN` map, never by literal index.

## The strips draw buckets, not readings

`Dashboard::readings()` averages the window into fixed slots - ten minutes on an hour or a day, thirty on a week, an hour on a month (`ChartRange::bucketSeconds()`, never zero). Every slot in the window is a row whether or not a reading landed in it: an empty one carries nulls, which ECharts draws as a hole in the line, so an outage shows as a gap instead of a straight line joining its neighbours. A window with no reading at all is the empty list, which is what "Nothing in this range" keys on - `hasReadings` must not be taught to look inside the rows.

The rows are dated by the slot, not by any reading in it, and the buckets divide the epoch (`timestamp / step`), so they never move with daylight saving and a station that uploads minutes off the slot still lands in the right one.

The averaging is SQL, and PostgreSQL's SQL: `Dashboard::buckets()` lays the slots out with `generate_series` and left-joins the averages onto them, reading the jsonb blob with the protocol keys directly. The mean is `AVG(data->>'temperature')` - V2 keeps the V1 key for its window mean on purpose, so one expression averages both versions. The extremes are `MIN(COALESCE(data->>'temperature_min', data->>'temperature'))` and the `MAX` of the `_max` key: a V1 row has no extremes and stands in as its own, which is the same statement `MeasurementDataV1` makes (`temperatureMin === temperature`, `samples === 1`). A later protocol that renames a field has to teach that query about it as well - aggregation cannot go through `ProtocolVersion::hydrate()` row by row. See `.ai/rules/database.md` for why there is no SQLite fallback.

The mean is turned back into a `MeasurementDataV1` so the pressure reduction and the dew point run through the same code as a single reading.

The footer's record count is `recordCount()`, a count in the table bounded to the window - `count($this->readings)` is the number of slots, holes included, and would call a month the station slept through seven hundred records.

The payload therefore ends on the window's last slot, which is a hole whenever the station is a few minutes late. Anything reading "the last row" wants the last row that holds a reading - `newestPlottedReading()`, which is how the hero readouts fall back when the station has been quiet for over a day.

## The window stops at a month, and the buckets at an hour

`normaliseWindow()` clips anything wider than `MAX_SPAN_SECONDS` (30 days) from the front, keeping the newer end the reader pointed at. A strip is about a thousand pixels across: a month of hourly means gives each day thirty of them and its rise and fall stays legible; a year would give it three, and averaging into six-hour buckets flattens the very swing the chart is for - the morning frost and the afternoon high become a temperature that never happened. `ChartRange` has no case past `Month` for that reason, and `forSpan()` falls to it.

A min-max band is drawn behind each line (`bandSeries()` in `station-charts.js`: two stacked line series, the lower invisible along the minimum, the upper filled up to the maximum, `stackStrategy: "all"` because a winter minimum is negative). It is the spread of the samples in the bucket, not of the bucket means: with V2 a ten-minute slot already carries the lowest and highest half-minute reading, so the band is visible at the station's own cadence and widens with the bucket. An earlier band built on V1 means alone was invisible at the hour and was taken out for it; that is not this band. Over V1 history the band has no width and draws nothing.

The navigator is unaffected - it always spans the whole record, so a month is found by dragging its slider.

Storage stays UTC: the firmware sends `time(nullptr)` and `config/app.php` keeps `'timezone' => 'UTC'`. Only presentation shifts.

## The window is from/to, and zooming re-queries

`range` is only a preset that seeds the window; `from`/`to` are real epochs and override it. Stepping by whole preset-sized units was the earlier design and made short windows unreachable without hammering an arrow.

A drag-selection sends real epochs to `zoomTo()`, which re-queries. That round trip is the point: the bucket width follows the span actually on screen (`ChartRange::forSpan()`), so zooming into a month of hourly means comes back as the ten-minute slots the month view averaged over.

`normaliseWindow()` sorts, clamps to the present and enforces a minimum and a maximum span - both ends arrive from the query string and from drags that may have been stray clicks.

## Components are class-based, not single-file

The dashboard was converted off the Livewire 4 single-file format because the PHP block kept outgrowing the template above it. Class in `app/Livewire`, view in `resources/views/livewire`, wired by class name in `routes/web.php`. `php artisan livewire:convert` only moves between SFC and MFC, so a class-based conversion is manual.

The layout resolves through Livewire's default `component_layout => 'layouts::app'`, which is why `resources/views/layouts/app.blade.php` needs no wiring of its own.

## Canvas regions carry wire:ignore; data arrives by attribute

ECharts owns its DOM, so each channel's container is `wire:ignore` and a morph must never reach it. New data reaches the canvas through a separate `data-chart-rows` element that Livewire does re-render; `station-charts.js` watches that attribute with a MutationObserver and calls `setOption`.

Each line on the shared strip can be switched off by its label, all but the last one on - `Dashboard::toggleChannel()` refuses that and the template disables the button. The dew point is derived (`DewPoint`, Magnus formula) and starts off. The switches are a Livewire property (`$channels`), not Alpine or JS state: the hidden keys reach the canvas as `data-hidden-channels` on the payload element, the observer watches it and `paintKey()` includes it, so the choice survives a poll. Not `#[Url]`, so a reload starts from the defaults - that is the point of a default. The dew point shares the temperature's value axis - channels in `station-charts.js` name the axis they are read against.

Controls that appear conditionally in the right-aligned toolbar shift everything beside them. Render them always and disable them instead - a button that pops in on first use slides the neighbouring controls out from under the pointer mid-click.

## The dashboard polls, and a zoomed window freezes the charts

`wire:poll.60s` on the dashboard root refreshes the page. No broadcasting: the station uploads once per ten minutes, so Reverb or Pusher would be a websocket server and a dependency for nothing.

Nothing suppresses the poll while a reader is zoomed - the freeze falls out of the design. A zoomed window is a pair of fixed epochs, so the re-query returns the same readings and `data-chart-rows` morphs back identical; an unchanged attribute produces no MutationObserver record, so the canvases are never repainted under the reader.

`station-charts.js` watches `data-navigator-rows` too, because the navigator always spans the whole record and does grow while zoomed. Since one mount serves both, `render()` repaints the channels only when the chart payload actually changed (`painted`), or when `mount(true)` forces it - a theme switch or a Livewire navigation. Do not call `mount` straight from an observer or event listener: the first argument would land in `force`.

## Pressure is stored raw and reduced to sea level only for display

The BME280 sends station pressure, and the record keeps it verbatim - protocol V1 does not change. Everything shown on the dashboard goes through `SeaLevelPressure::reduce()` with `Dashboard::ALTITUDE_METRES` (345 m, Plzeň-Slovany), so a corrected height never means rewriting stored rows.

The reduction is hypsometric and uses the reading's own temperature, not the standard atmosphere's fixed 15 °C: at 345 m the difference between a frost and a heatwave is about 6 hPa, and the sensor already measures it.

Consequence for the payload tail: the raw JSON prints station pressure in Pa while the converted column beside it is sea-level hPa. They are meant to differ by ~40 hPa - that is not a bug.

The chart payload carries two decimals, the sensor's own resolution - it reports whole pascals. The pressure strip's axis scales to whatever the window holds, and a day of weather is a couple of hPa, so tenths drew the line as a staircase. The hero readouts and the payload tail still print a tenth.

## The hero's day extremes are the samples', not the means'

`lastDay` rows carry `tMin/tMax`, `hMin/hMax`, `pMin/pMax` off each entry's extremes and `figures()` takes the day's min and max from those, while `now` and the trend stay on the mean. Over V2 the coldest sample of the night sits below the coldest ten-minute mean, and that is the number a "24 h min" promises. The pressure extremes are reduced to sea level with the entry's own mean temperature (`withPressure()`): the sample that read the extreme kept no temperature of its own.

## The payload tail prints whatever keys the entry's version carries

`recentTransmissions` hands the view `packet`, the value object's `jsonSerialize()`, and the template loops over it, colouring each field by the first word of its key. Do not go back to naming the four V1 fields in the template: a V2 packet has eleven, and the tail's job is to show the entry as it arrived.

## The navigator thins, it does not average

The strips average (above); the navigator keeps one real reading per six-hour bucket through `Dashboard::firstPerBucket()`. It exists to show where the window sits, so a sample per bucket is enough for its few pixels and the averaging would be work for nothing. `overview()` skips thinning entirely below OVERVIEW_UNTHINNED_ROWS, because a record shorter than one bucket would otherwise thin down to a single point.

Thinning by the phase of the epoch misses a drifting station: it stamps an upload when it wakes, not on the slot, so its timestamps sit a couple of minutes off every multiple of STEP_SECONDS. `whereRaw('timestamp % ? < ?')` only lands on a row while that drift stays under the step, and a record shorter than one bucket holds no such row at all - which is what emptied the navigator on a production database a few hours old. `firstPerBucket()` therefore groups on `MIN(timestamp)` per `timestamp / bucket` and takes the bucket's own first row whatever time it carries.

## Measurement time is the station's stamp; arrival time is created_at

Two different clocks, and the dashboard shows each where it answers something.

`lastMeasurement()` (the status line, and `isSilent`) reads `MAX(timestamp)` - the station's own stamp. A lost link buffers readings in RTC memory and delivers them late, so the newest row's `created_at` says nothing about how long ago the sensor was last read.

The payload tail dates its rows by `created_at` instead. The reading's own stamp is already printed in the JSON beside it, so formatting it again as a date would say the same thing twice; arrival is the other half of the story and the only place the delivery gap shows. Both go through `localise()` - stored stamps are UTC, the page reads Europe/Prague.

## The navigator draws on two x-axes

A slider dataZoom narrows the axis it drives to the selected window. The navigator's grid shares the canvas with that slider, so anything drawn against the driven axis - tick labels, event markLines - reads the window while the slider's shadow above spans the whole record. They looked aligned only by coincidence until the event lines landed.

`navigatorOption()` therefore has xAxis[0] hidden and driven by the slider (`xAxisIndex: 0`), and xAxis[1] pinned to `dataMin`/`dataMax` carrying the labels and the markLines, fed the same overview rows by a second invisible series. Both span the record, so the slider's shadow and the labelled axis line up. Do not collapse them back into one axis.

## The dashboard shows one sensor, and every query is scoped to it

`sensors` is a table; the firmware's `sensor_name` is only the key `StoreMeasurementController` uses to `firstOrCreate` one. Measurements and station events carry `sensor_id` (both FKs, restrict on delete), so an event belongs to one sensor's charts.

`Dashboard::$sensor` (`#[Url]`) is the selected sensor's slug - the firmware's own name made URL-safe by `Sensor::uniqueSlug()` on creation (`Str::slug`, counter on collision, `sensor` for a name of only symbols), so the link reads `?sensor=sensor-001` and survives a reseed. Every measurement query goes through `measurements()`, which scopes to `selectedSensor` - including the `firstPerBucket()` subquery, because another station's earlier row in the same bucket would otherwise be the bucket's MIN and this sensor's row would drop out. `buckets()` is a query-builder query, not Eloquent, and scopes itself the same way; unscoped it would average the other station into this one's line. `stationEvents()` is scoped the same way.

`normaliseSensor()` pins `$sensor` to the shown sensor's slug in `mount()` and `updatedSensor()`: the native `<select>` is bound to the property and shows a blank when given a value it has no option for. Livewire keeps the initial value out of the query string, so the default stays a clean URL. Unknown slugs fall back to the first registered sensor (lowest id), which is why the original station keeps the front page when a second one appears. The picker renders only with two or more sensors and stands alone on the right of its own row, so its arrival shifts nothing.
