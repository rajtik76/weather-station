// Copy this file to secrets.h and fill in the real values.
//
// secrets.h is gitignored on purpose: it holds the WiFi passwords and the
// API token for a live endpoint. Never commit it. If it ever lands in a
// commit, rotate all of them - git history cannot be scrubbed reliably
// once pushed.

#ifndef SECRETS_H
#define SECRETS_H

// Sent as "sensor_name". The server registers a sensor under this name on
// its first upload and files every later one under it.
#define DEVICE_ID "sensor-001"

// The network the station lives on.
#define PRIMARY_WIFI_SSID "your-ssid"
#define PRIMARY_WIFI_PASS "your-password"

// A second network to fall back to when the primary will not associate or
// its uplink is dead - a different provider, ideally. Leave the SSID empty
// to run on the primary alone.
#define BACKUP_WIFI_SSID ""
#define BACKUP_WIFI_PASS ""

// Sent as "Authorization: Bearer <token>". Must match SENSOR_API_TOKEN in
// the server's .env, otherwise the API answers 401 and readings stay
// buffered on the device.
#define BEARER_TOKEN "your-api-token"

#endif
