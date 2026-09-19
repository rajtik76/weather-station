# How the readings are stored

Everything a station uploads lands in three tables: `sensors` for the
stations themselves, `measurements` for the readings and `station_reports`
for the board's account of its own state. This is what a row looks like,
how the payload versions differ and what happens to the rows over time.

## A measurement row

A row is one ten-minute window of one station: `sensor_id`, `timestamp`,
`protocol_version` and a `jsonb` blob with the readings as the firmware sent
them, fixed-point integers included. `(sensor_id, timestamp)` is unique and
the endpoint upserts on it, so a resent batch overwrites rather than
duplicates. The blob is stored as the firmware sent it; conversion to °C, %
and hPa happens on the way out, and the sea-level reduction of pressure too
(one height for the site, `Dashboard::ALTITUDE_METRES`), so a corrected
height never means rewriting the record.

`protocol_version` is a column of its own and never lives inside the blob.
`App\Enums\ProtocolVersion` maps a version to the value object in
`App\ValueObject\` that validates and decodes it. A new firmware format is a
new case and a new class; rows written by older firmware stay readable and
keep their version.

## The versions

**V1** was one reading every ten minutes: `temperature`, `humidity`,
`pressure`.

**V2** is a ten-minute window of half-minute readings. The mean goes out
under the V1 keys, and beside it `_min` and `_max` for each channel and
`samples`, the count of readings in the window. The V1 names for the mean are
deliberate: the dashboard averages both versions with one SQL expression, and
a V1 row stands in as its own minimum and maximum. The band behind each line
on the chart is the spread between those extremes.

A station can resend a timestamp under a newer version after a firmware
upgrade; the upsert takes the new blob and the new version number.

## Station reports

A batch may carry a `station` object: firmware version, reset reason, uptime,
heap, which network the board is on, how many windows wait on it, how many
uploads failed in a row and how far the clock had drifted by its last NTP
re-sync. It goes into `station_reports` as one `jsonb` row per batch, tied to
the sensor. The dashboard shows the newest under the payload tail - the
board's own account of how it was doing, readable after it has stopped
answering. The object is optional as a whole, but once present it has to be
complete - except for the fields a later firmware added, which stay optional
so a station in the field keeps uploading through a server upgrade.

## Aggregation

The dashboard does not read rows; it asks PostgreSQL for buckets. The bucket
width follows the span on screen - ten minutes up to a day, half an hour up
to a week, an hour up to a month - and one `generate_series` query per render
folds the rows into those buckets with the mean of the means and the extreme
of the extremes. That is why the test suite and local development run on PostgreSQL
rather than SQLite: the SQL has no portable form.

## Retention

Nothing is pruned or rolled up. The half-minute readings never leave the
board; what arrives is one row per ten-minute window, 144 a day per station,
about 53 000 a year - a few megabytes of PostgreSQL. The full resolution is
kept for good.

Rollups can come later, once the record is long enough that a month-wide
query gets slow; nothing in the schema stands in the way. `station_reports`
grows at the same rate, one row per batch, and is the first candidate for
pruning if the volume ever matters - a report from last year says nothing a
newer one does not.
