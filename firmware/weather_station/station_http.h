#ifndef STATION_HTTP_H
#define STATION_HTTP_H

#include "station_status.h"

// A plain HTTP server on the LAN, so the station can be looked at without
// a cable - plugging one in resets the board, which is the one thing a
// look should not do. Reachable as http://<STATION_HOSTNAME>.local/ on
// whichever network the station is on, and by IP.
//
//   /           what the station is doing, as text
//   /status     the same as JSON, with everything the LAN may see
//   /log        the log ring in RAM, oldest line first
//   /log/flash  the log on the flash, which survives a restart
//
// No authentication: it only reads, and it is only on the LAN.
#define STATION_HOSTNAME "weather-station"

// Where ArduinoOTA listens. Advertised over mDNS with the HTTP service,
// so the IDE and arduino-cli find the board as a network port.
#define STATION_OTA_PORT 3232

// Starts the server. `refresh` is called before every answer so the
// status it hands out is current.
void stationHttpBegin(const station_status_t* status, void (*refresh)());

// Registers the mDNS name. Call every time the WiFi comes up: the
// responder binds to the interface as it was when it started. The OTA
// port is advertised only when something listens on it, so the IDE does
// not offer a network port that would time out.
void stationHttpAnnounce(bool otaListening);

// Serves whatever is waiting. Call from the loop.
void stationHttpHandle();

#endif
