# Ingest API

Validated by `App\Http\Requests\StoreMeasurementRequest`, stored by `App\Http\Controllers\StoreMeasurementController`. Firmware side: [firmware README](../firmware/README.md#protocol).

## Endpoint

```
POST /api/v1/measurement
Authorization: Bearer <SENSOR_API_TOKEN>
Content-Type: application/json
```

- One token for all stations, compared in constant time against `SENSOR_API_TOKEN`
- Empty token on the server denies everyone
- 10 requests per minute per IP

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

| Field              | Rule                                                           |
| ------------------ | -------------------------------------------------------------- |
| `sensor_name`      | string, 3 to 50 characters; registers the station on first use |
| `protocol_version` | `1` to `4`; decides which fields each measurement carries      |
| `measurements`     | 1 to 500 entries                                               |

`timestamp`: UTC Unix seconds, 1 to 4294967295. All values are integers: hundredths of °C and %, Pa.

| Field                                | V1  | V2+ | Range           |
| ------------------------------------ | --- | --- | --------------- |
| `temperature`                        | yes | yes | -4000 .. 8500   |
| `humidity`                           | yes | yes | 0 .. 10000      |
| `pressure`                           | yes | yes | 30000 .. 110000 |
| `temperature_min`, `temperature_max` |     | yes | as above        |
| `humidity_min`, `humidity_max`       |     | yes | as above        |
| `pressure_min`, `pressure_max`       |     | yes | as above        |
| `samples`                            |     | yes | 1 .. 65535      |

- **V1** - one reading per entry; stands in as its own minimum and maximum
- **V2** - ten-minute window of half-minute readings: bare field is the mean, `_min` / `_max` the extremes, `samples` the count; `_min` above the mean or `_max` below it fails validation
- **V3** - V2 plus optional `noise` object per measurement
- **V4** - V3 plus optional illuminance

### Illuminance (V4)

- VEML7700 on the shield hub; present only when the window had at least one reading, then all three fields are required
- Integers in hundredths of a lux, 0 .. 15000000; same min/mean/max rule as other channels
- Measured inside the radiation shield: shape is the signal, absolute lux are not comparable with another station
- Drawn on a log axis

| Field                                | Meaning                          |
| ------------------------------------ | -------------------------------- |
| `illuminance`                        | mean over the window             |
| `illuminance_min`, `illuminance_max` | lowest and highest reading in it |

### `noise` object (V3+)

Optional as a whole; once present every field is required.

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

`bands` is the third-octave spectrum at 25, 31.5, 40, 50, 63, 80, 100, 125, 160, 200, 250, 315, 400, 500, 630, 800, 1000, 1250, 1600, 2000, 2500, 3150, 4000, 5000, 6300, 8000 Hz.

### `station` object

Optional as a whole; once present every field is required. Keys not in the rules are dropped before storing.

| Field                                                                             | Type                    |
| --------------------------------------------------------------------------------- | ----------------------- |
| `firmware`                                                                        | string, up to 32        |
| `board`                                                                           | string, up to 40        |
| `reset_reason`                                                                    | string, up to 40        |
| `uptime`, `heap_free`, `heap_min`, `wifi_switches`, `buffered`, `upload_failures` | integer, 0 or more      |
| `ssid`, `ip`                                                                      | string or null          |
| `rssi`                                                                            | integer, -120 .. 0      |
| `wifi_network`                                                                    | `0` primary, `1` backup |

Exceptions, so older builds keep uploading:

- `clock_step_ms`, `clock_step_over_s`, `clock_step_max_ms`, `clock_synced_at` (firmware 2.2+): all four or none; a partial set is refused
- `board` (`ARDUINO_BOARD`: `ESP32C3_DEV`, `DFROBOT_FIREBEETLE_2_ESP32C6`, `ESP32_DEV`, firmware 2.3+) may be left out

### Firmware and server versions

Firmware is tagged `fw/v<version>` ([`firmware/CHANGELOG.md`](../firmware/CHANGELOG.md)), the app `v<version>`.

| Payload                                           | Firmware from | Server from |
| ------------------------------------------------- | ------------- | ----------- |
| `protocol_version` 1                              | 1.0.0         | v1.0.0      |
| `protocol_version` 2, windows with extremes       | 2.0.0         | v2.0.0      |
| `station` object                                  | 2.1.0         | v2.1.0      |
| `station.clock_step_*`, `station.clock_synced_at` | 2.2.0         | v2.2.0      |
| `station.board`                                   | 2.3.0         | v3.0.0      |
| `protocol_version` 3, `noise` per window          | 3.0.0         | v3.0.1      |
| `protocol_version` 4, illuminance per window      | 4.0.0         | v4.0.0      |

- A server older than the row refuses a V2, V3 or V4 batch (unknown `protocol_version`) and ignores an unknown `station` or `noise` object; every later field is optional
- A firmware older than the row does not send the field; the dashboard leaves the row out
- `board` is absent from the payload before 2.3; the changelog is the only record of the hardware

## Response

| Status | When                                                                       |
| ------ | -------------------------------------------------------------------------- |
| `201`  | Stored. Body `{"stored": n}`, rows written or replaced.                    |
| `401`  | Missing or wrong bearer token. Body `{"message": "Unauthenticated."}`.     |
| `422`  | Validation failed. Laravel's `{"message": ..., "errors": {field: [...]}}`. |
| `429`  | More than 10 requests in a minute.                                         |

- Validation is all or nothing: one invalid entry rejects the batch, nothing is stored
- After storing, once the response has gone out: `SENSOR_HEARTBEAT_URL` is requested if set
- Same for `FORECAST_URL`: the first upload of each hour asks the forecast service for a forecast ([scoring](scoring.md#stored)); a failure is logged and ignored, the next upload in the hour retries

## Storage

| Table             | Holds                                                                                      |
| ----------------- | ------------------------------------------------------------------------------------------ |
| `sensors`         | stations; created by the first upload under a new `sensor_name`, description added by hand |
| `measurements`    | one row per window per station                                                             |
| `station_reports` | one `jsonb` row per batch, tied to the sensor; newest shown under the payload tail         |
| `forecasts`       | one row per forecast service run                                                           |

- `measurements`: `sensor_id`, `timestamp`, `protocol_version`, `jsonb` blob with the version's fields as the firmware sent them
- `(sensor_id, timestamp)` is unique; the endpoint upserts (new blob and version replace the old), so a resent batch never duplicates
- Conversion to °C, % and hPa and the sea-level pressure reduction (`StationSite::ALTITUDE_METRES`) happen on read
- `protocol_version` is its own column; `App\Enums\ProtocolVersion` maps a version to its value object in `App\ValueObject\`; a new format is a new case and class
- `forecasts`: `sensor_id`, `issued_at` (ten-minute window of the starting reading, unique per sensor), `model` (the model's `trained_at`, see [`forecast/CHANGELOG.md`](../forecast/CHANGELOG.md)), whether the station correction applied, six horizons as `jsonb` in °C, % and hPa; service contract in [`forecast/README.md`](../forecast/README.md#the-services-contract)

## Aggregation

- The dashboard queries PostgreSQL for buckets: ten minutes up to a day, half an hour up to a week, an hour up to a month
- One `generate_series` query per render; mean of the means, extreme of the extremes (the band behind each line)
- Tests and local development need PostgreSQL; the SQL has no portable form

## Retention

- Nothing is pruned or rolled up
- One row per ten-minute window: 144 a day per station, about 53 000 a year
- `station_reports` grows at the same rate and is the first candidate for pruning
