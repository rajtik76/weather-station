#ifndef VEML7700_H
#define VEML7700_H

#include <stdint.h>

// The VEML7700 on the shield hub, facing the east louvers, at 0x10 on the
// shared bus. Driven through its registers, no library. It integrates
// continuously; a read takes the last finished integration, so it costs one
// short I2C transfer and fits inside noiseHush().

// False when the sensor did not answer; vemlRead() tries again.
bool vemlBegin();

// Illuminance in 0.01 lx. False when the sensor did not answer or the
// integration saturated; either way the range is adjusted for the next read.
bool vemlRead(uint32_t& centilux);

#endif
