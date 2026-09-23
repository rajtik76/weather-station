# The ingest API and what happens to a batch

What the server accepts from a station, what it refuses, what it answers,
and what becomes of the rows afterwards. The contract is enforced in
`App\Http\Requests\StoreMeasurementRequest` and stored by
`App\Http\Controllers\StoreMeasurementController`; the firmware's side of
the payload, with the reasoning behind it, is in the
[firmware README](../firmware/README.md#protocol).

## Endpoint

```
POST /api/v1/measurement
Authorization: Bearer <SENSOR_API_TOKEN>
Content-Type: application/json
```

One token for every station, compared in constant time against
`SENSOR_API_TOKEN`. An empty token on the server denies everyone rather than
letting everyone in. 10 requests per minute per IP; the station reports every
ten minutes, so the limit only ever bites a retry loop gone wrong.

## Request

```json
{
    "sensor_name": "sensor-001",
    "protocol_version": 2,
    "measurements": [
        {
            "timestamp": 1757000000,
            "temperature": 2602,
            "temperature_min": 2588,
            "temperature_max": 2631,
            "humidity": 4871,
            "humidity_min": 4820,
            "humidity_max": 4910,
            "pressure": 97389,
            "pressure_min": 97381,
            "pressure_max": 97396,
            "samples": 20
        }
    ],
    "station": {
        "firmware": "2.3.0",
        "board": "ESP32C3_DEV",
        "reset_reason": "power on",
        "uptime": 86400,
        "heap_free": 183000,
        "heap_min": 149000,
        "ssid": "home",
        "ip": "192.168.0.200",
        "rssi": -61,
        "wifi_network": 0,
        "wifi_switches": 0,
        "buffered": 0,
        "upload_failures": 0,
        "clock_step_ms": 412,
        "clock_step_over_s": 3600,
        "clock_step_max_ms": 412,
        "clock_synced_at": 1757000000
    }
}
```

| Field              | Rule                                                               |
| ------------------ | ------------------------------------------------------------------ |
| `sensor_name`      | string, 3 to 50 characters. Registers the station on first use.    |
| `protocol_version` | `1`, `2` or `3`. Decides which fields each measurement must carry. |
| `measurements`     | 1 to 500 entries.                                                  |

Every measurement carries a `timestamp`: UTC Unix seconds, 1 to 4294967295.
The remaining fields depend on the version. All values are integers, in
hundredths of °C and %, and in Pa.

| Field                                | V1  | V2  | Range           |
| ------------------------------------ | --- | --- | --------------- |
| `temperature`                        | yes | yes | -4000 .. 8500   |
| `humidity`                           | yes | yes | 0 .. 10000      |
| `pressure`                           | yes | yes | 30000 .. 110000 |
| `temperature_min`, `temperature_max` |     | yes | as above        |
| `humidity_min`, `humidity_max`       |     | yes | as above        |
| `pressure_min`, `pressure_max`       |     | yes | as above        |
| `samples`                            |     | yes | 1 .. 65535      |

**V1** was one reading every ten minutes. **V2** is a ten-minute window of
half-minute readings: the bare field is the mean over the window, `_min` and
`_max` its extremes, `samples` how many readings went in. A `_min` above the
mean or a `_max` below it fails validation. The mean keeps the V1 key on
purpose - the dashboard averages both versions with one SQL expression, and
a V1 row stands in as its own minimum and maximum. **V3** is a V2 window
with an optional `noise` object added to each measurement.

### The `noise` object

Present only when the microphone produced data for that ten-minute window;
a V3 measurement without it is still valid. Optional as a whole, once
present every field below is required.

```json
"noise": {
    "seconds": 600,
    "laeq": 4312,
    "lamax": 6120,
    "la10": 4705,
    "la90": 3890,
    "bands": [2210, 2345, 2098, 1876, 1654, 1432, 1298, 1187, 1065, 987, 912, 856, 798, 745, 698, 654, 612, 578, 542, 498, 456, 412, 378, 342, 298, 254]
}
```

| Field     | Type                                                             | Range                       |
| --------- | ---------------------------------------------------------------- | --------------------------- |
| `seconds` | integer, one-second levels in the window                         | 1 .. 600                    |
| `laeq`    | integer, hundredths of dB(A), energy mean over the window        | 0 .. 15000, `laeq <= lamax` |
| `lamax`   | integer, hundredths of dB(A), loudest one-second Leq             | 0 .. 15000                  |
| `la10`    | integer, hundredths of dB(A), level exceeded 10 % of the seconds | 0 .. 15000, `la10 <= lamax` |
| `la90`    | integer, hundredths of dB(A), level exceeded 90 % of the seconds | 0 .. 15000, `la90 <= la10`  |
| `bands`   | 26 integers, hundredths of dB, unweighted (Z)                    | each 0 .. 15000             |

`bands` is the third-octave spectrum, in order, for the nominal centre
frequencies 25, 31.5, 40, 50, 63, 80, 100, 125, 160, 200, 250, 315, 400, 500,
630, 800, 1000, 1250, 1600, 2000, 2500, 3150, 4000, 5000, 6300, 8000 Hz.

### The `station` object

The board's account of itself at the time of the upload. Optional as a
whole; once present, every field below is required, so a firmware that
reports is held to the shape the dashboard reads. Keys the rules do not name
are dropped before storing.

| Field                                                                             | Type                    |
| --------------------------------------------------------------------------------- | ----------------------- |
| `firmware`                                                                        | string, up to 32        |
| `board`                                                                           | string, up to 40        |
| `reset_reason`                                                                    | string, up to 40        |
| `uptime`, `heap_free`, `heap_min`, `wifi_switches`, `buffered`, `upload_failures` | integer, 0 or more      |
| `ssid`, `ip`                                                                      | string or null          |
| `rssi`                                                                            | integer, -120 .. 0      |
| `wifi_network`                                                                    | `0` primary, `1` backup |

Two exceptions, so an older build keeps uploading through a server
upgrade. The clock drift set - `clock_step_ms`, `clock_step_over_s`,
`clock_step_max_ms`, `clock_synced_at` - was added in firmware 2.2 and may
be left out as a whole, but all four or none; a half-reported set is
refused rather than shown. `board`, the IDE's board selection
(`ARDUINO_BOARD`: `ESP32C3_DEV`, `DFROBOT_FIREBEETLE_2_ESP32C6`,
`ESP32_DEV`), was added in firmware 2.3 and may be left out.

### Firmware and server versions

The firmware is tagged `fw/v<version>` on the commit it was built from and
its history is [`firmware/CHANGELOG.md`](../firmware/CHANGELOG.md); the app
is tagged `v<version>`. What each side introduced, and the first release of
the other side that understands it:

| Payload                                           | Firmware from | Server from |
| ------------------------------------------------- | ------------- | ----------- |
| `protocol_version` 1                              | 1.0.0         | v1.0.0      |
| `protocol_version` 2, windows with extremes       | 2.0.0         | v2.0.0      |
| `station` object                                  | 2.1.0         | v2.1.0      |
| `station.clock_step_*`, `station.clock_synced_at` | 2.2.0         | v2.2.0      |
| `station.board`                                   | 2.3.0         | v3.0.0      |
| `protocol_version` 3, `noise` per window          | 3.0.0         | v3.0.0      |

A server older than the row refuses a V2 or V3 batch (unknown
`protocol_version`) and ignores a `station` object or `noise` object it does
not know; it refuses nothing else from a newer firmware, because every
later field is optional. A firmware
older than the row simply does not send the field, and the dashboard leaves
the row out. The board itself never appears in the payload before 2.3;
for those rows the changelog is the only record of the hardware.

## Response

| Status | When                                                                             |
| ------ | -------------------------------------------------------------------------------- |
| `201`  | Stored. Body `{"stored": n}`, the number of rows written or replaced.            |
| `401`  | Missing or wrong bearer token. Body `{"message": "Unauthenticated."}`.           |
| `422`  | Validation failed. Laravel's usual `{"message": ..., "errors": {field: [...]}}`. |
| `429`  | More than 10 requests in a minute.                                               |

Validation is all or nothing: one invalid entry rejects the whole batch and
nothing from it is stored. After a batch is stored the server requests
`SENSOR_HEARTBEAT_URL`, if set, once the response has gone out; a slow or
failing monitor never delays or fails the upload.

## Storage

Three tables: `sensors` for the stations, `measurements` for the readings,
`station_reports` for the board's state.

A sensor is created by the first upload under a new `sensor_name`; a
description can be added by hand afterwards and is what the dashboard shows
beside the name.

A measurement row is one window of one station: `sensor_id`, `timestamp`,
`protocol_version` and a `jsonb` blob with the fields its version defines,
the integers as the firmware sent them and nothing else. `(sensor_id,
timestamp)` is unique and the endpoint upserts on it, so a resent batch
overwrites rather than duplicates - and a station may resend a window under
a newer firmware, the upsert takes the new blob and the new version number.
Conversion to °C, % and hPa happens on the way out, and so does the
sea-level reduction of pressure (one height for the site,
`Dashboard::ALTITUDE_METRES`), so a corrected height never means rewriting
the record.

`protocol_version` is a column of its own and never lives inside the blob.
`App\Enums\ProtocolVersion` maps a version to the value object in
`App\ValueObject\` that validates and decodes it. A new firmware format is a
new case and a new class; rows written by older firmware stay readable and
keep their version.

A station report is one `jsonb` row per batch, tied to the sensor. The
dashboard shows the newest under the payload tail - the board's own account
of how it was doing, readable after it has stopped answering.

## Aggregation

The dashboard does not read rows; it asks PostgreSQL for buckets. The bucket
width follows the span on screen - ten minutes up to a day, half an hour up
to a week, an hour up to a month - and one `generate_series` query per render
folds the rows into those buckets with the mean of the means and the extreme
of the extremes. The band behind each line on the chart is the spread between
those extremes. That is why the test suite and local development run on
PostgreSQL rather than SQLite: the SQL has no portable form.

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
