---
paths:
    - "firmware/**"
---

# Firmware

## Re-sync the clock hourly; the RTC oscillator drifts

`syncNtp()` must re-sync at least once an hour (`NTP_RESYNC_AFTER_S`). Do not widen that interval to save radio time - the radio is already up for the upload, so a sync costs a fraction of a second awake.

Why: deep sleep is timed by the ESP32's internal RC oscillator, accurate to within a few percent. A firmware that synced only at cold boot let the clock free-run and it gained four minutes, so the dashboard reported the last transmission as arriving "4 minutes from now".

Wait on `sntp_get_sync_status() == SNTP_SYNC_STATUS_COMPLETED`, never on the clock looking plausible: on a re-sync it already does, so that test returns before the server has answered and corrects nothing.

A timed-out sync returns whether the clock is set rather than false. An old but sane clock still stamps the reading closely enough and still validates the TLS certificate, and dropping the reading over it loses data for nothing.

The server stores what the device sends, verbatim, so any skew here lands straight in the record.
