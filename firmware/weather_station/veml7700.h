#ifndef VEML7700_H
#define VEML7700_H

#include <stdint.h>

// VEML7700 at 0x10 on the shared bus, driven through registers. A read takes the last
// finished integration: one short transfer, fits inside noiseHush().

// False when the sensor did not answer; vemlRead() tries again.
bool vemlBegin();

// Illuminance in 0.01 lx. False when the sensor did not answer or saturated.
bool vemlRead(uint32_t& centilux);

#endif
