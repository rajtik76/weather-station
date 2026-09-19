#ifndef STATION_HTTP_H
#define STATION_HTTP_H

#include "station_status.h"

// Plain HTTP on the LAN, so the station can be looked at without the
// cable that resets it. http://<STATION_HOSTNAME>.local/ or by IP.
//
//   /           status as text
//   /status     status as JSON
//   /log        the RAM ring, oldest first
//   /log/flash  the flash log
//
// No authentication: read-only, LAN only.
#define STATION_HOSTNAME "weather-station"

// ArduinoOTA port, advertised over mDNS so the IDE finds the board.
#define STATION_OTA_PORT 3232

// `refresh` runs before every answer so the status is current.
void stationHttpBegin(const station_status_t* status, void (*refresh)());

// Call every time WiFi comes up: the responder binds to the interface as
// it was at start. OTA is advertised only while something listens on it.
void stationHttpAnnounce(bool otaListening);

// Call from the loop.
void stationHttpHandle();

#endif
