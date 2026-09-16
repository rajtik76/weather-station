// Copy this file to secrets.h and fill in the real values.
//
// secrets.h is gitignored on purpose: it holds the WiFi password and the
// API token for a live endpoint. Never commit it. If it ever lands in a
// commit, rotate both credentials - git history cannot be scrubbed
// reliably once pushed.

#ifndef SECRETS_H
#define SECRETS_H

// Sent as "sensor_name". The server registers a sensor under this name on
// its first upload and files every later one under it.
#define DEVICE_ID "sensor-001"

// Wifi
#define WIFI_SSID "your-ssid"
#define WIFI_PASS "your-password"

// Sent as "Authorization: Bearer <token>". Must match SENSOR_API_TOKEN in
// the server's .env, otherwise the API answers 401 and readings stay
// buffered on the device.
#define BEARER_TOKEN "your-api-token"

#endif
