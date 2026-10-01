// Copy to secrets.h; it is gitignored. If it lands in a commit, rotate everything in it.

#ifndef SECRETS_H
#define SECRETS_H

// Sent as "sensor_name"; registered on first upload.
#define DEVICE_ID "sensor-001"

// Primary network.
#define PRIMARY_WIFI_SSID "your-ssid"
#define PRIMARY_WIFI_PASS "your-password"

// Backup network, ideally a different provider. Empty SSID: primary only.
#define BACKUP_WIFI_SSID ""
#define BACKUP_WIFI_PASS ""

// Must match SENSOR_API_TOKEN on the server.
#define BEARER_TOKEN "your-api-token"

// Empty switches OTA off; an open OTA port takes any image from anyone on the LAN.
#define OTA_PASSWORD ""

#endif
