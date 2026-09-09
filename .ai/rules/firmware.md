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

## FireBeetle 2 ESP32-C6: enable USB CDC On Boot, and RTC data is safe

Board is DFRobot FireBeetle 2 ESP32-C6 (ESP32 core 3.x, RISC-V). I2C pads SDA=GPIO19 / SCL=GPIO20, on-board LED GPIO15 (pad D13). The sketch reads these from the variant via SDA / SCL / LED_BUILTIN, so do not hardcode numbers again.

"USB CDC On Boot" ships Disabled, which points Serial at UART0 on GPIO16/17. The sketch still builds and runs, and the serial monitor stays empty - set it to Enabled before blaming the firmware. With CDC on, Serial.setTxTimeoutMs(0) keeps a write from blocking the wakeup when no host is attached.

RTC_DATA_ATTR survives deep sleep here. The C6 has no RTC slow memory, so those symbols land in RTC fast memory (verified: they link at 0x5000xxxx), and the C6 does not define SOC_PM_SUPPORT_RTC_FAST_MEM_PD, so the domain cannot be powered down.

Image fills ~89% of the default 1.2 MB app partition; a growing sketch needs the Minimal (1.3MB APP) scheme.

## A missing serial port on the C6 means deep sleep, not a failed flash

The FireBeetle 2 ESP32-C6 has no USB-serial chip - the port is the CPU's own USB peripheral. Deep sleep powers it down, so /dev/cu.usbmodem* vanishes for the whole sleep interval and returns for the seconds the board is awake. An empty monitor and no port in the list is the normal state of a working station; the factory demo sketch only kept the port up because it never slept.

setup() waits up to USB_ATTACH_TIMEOUT_MS on a cold boot for a monitor to open the port, otherwise the whole log is printed before anyone is listening. Timer wakeups skip the wait - no host on the balcony.

To reflash a sleeping board: hold BOOT, tap RST, release BOOT. The chip stays in the bootloader, so the port stays up long enough for esptool.
