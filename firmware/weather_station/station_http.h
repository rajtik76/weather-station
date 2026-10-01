#ifndef STATION_HTTP_H
#define STATION_HTTP_H

#include "station_status.h"

// Plain HTTP, read-only, LAN only, no authentication.
//   /  status text   /status  JSON   /log  RAM ring   /log/flash  flash log
#define STATION_HOSTNAME "weather-station"

// ArduinoOTA port.
#define STATION_OTA_PORT 3232

// `refresh` runs before every answer.
void stationHttpBegin(const station_status_t* status, void (*refresh)());

// Call every time WiFi comes up: the mDNS responder binds to the interface as it was at start.
void stationHttpAnnounce(bool otaListening);

void stationHttpHandle();

#endif
