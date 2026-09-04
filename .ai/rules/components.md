---
paths:
  - 'resources/views/components/**'
---

# Components

## Flux charts force UTC - shift dates before they reach the view
Flux hard-codes the display zone in every date formatter its charts own. The axis ticks, tooltip heading and summary all build options as `{...format, timeZone: 'UTC'}`, spreading the caller's options FIRST, so a `timeZone` passed through `:format` is silently overwritten. There is no way to make a Flux chart render a non-UTC zone through its public API.

The workaround: convert the instant to the target wall clock server-side and tag it `Z` (see `wallClock()` in the dashboard component, which uses Europe/Prague). The `d` values in the chart payload are therefore wall-clock readings, not real instants - shift once, and never hand them to anything that does time-zone maths.

Anything else that reads that payload must format in UTC to replay the digits unchanged - `resources/js/chart-brush.js` and the `measuredAt` label both depend on this. Converting a second time shifts the label away from the axis it sits next to.

Storage stays UTC: the firmware sends `time(nullptr)` and `config/app.php` keeps `'timezone' => 'UTC'`. Only presentation shifts.
