// Standalone diagnostic sketch for the BME280 wiring.
// Not part of the weather_station sketch - flash it, read the serial log,
// then flash weather_station again.

#include <Wire.h>
#include <Adafruit_BME280.h>

// Must match BME280_SDA_PIN / BME280_SCL_PIN in weather_station.ino.
#define SDA_PIN 21
#define SCL_PIN 22

// Register 0xD0 holds a fixed chip id. It tells a real BME280 apart from
// a BMP280, which is pin compatible but has no humidity sensor.
#define REG_CHIP_ID 0xD0
#define CHIP_ID_BME280 0x60
#define CHIP_ID_BMP280 0x58

static Adafruit_BME280 bme;

static void profileSettling();

static void scanBus() {
  Serial.println("--- I2C scan ---");

  uint8_t found = 0;
  for (uint8_t addr = 1; addr < 127; addr++) {
    Wire.beginTransmission(addr);
    if (Wire.endTransmission() == 0) {
      Serial.printf("  device at 0x%02X\n", addr);
      found++;
    }
  }

  if (found == 0) {
    Serial.println("  nothing on the bus");
    Serial.println("  -> check VCC (3V3), GND, and that SDA/SCL are not swapped");
  }
}

// Returns false if the address does not answer at all.
static bool readChipId(uint8_t addr, uint8_t& id) {
  Wire.beginTransmission(addr);
  Wire.write(REG_CHIP_ID);
  if (Wire.endTransmission() != 0) return false;

  if (Wire.requestFrom(addr, (uint8_t)1) != 1) return false;
  id = Wire.read();
  return true;
}

static void reportChipId(uint8_t addr) {
  uint8_t id;
  if (!readChipId(addr, id)) return;

  Serial.printf("--- chip id at 0x%02X: 0x%02X ", addr, id);
  switch (id) {
    case CHIP_ID_BME280:
      Serial.println("(BME280, has humidity) ---");
      break;
    case CHIP_ID_BMP280:
      Serial.println("(BMP280 - pressure only, NO humidity) ---");
      break;
    default:
      Serial.println("(unknown - not a Bosch BMx280) ---");
      break;
  }
}

// Tries both strap options for SDO. Leaves the sensor initialised on
// whichever one answered.
static bool initSensor(uint8_t& usedAddr) {
  const uint8_t addrs[] = {0x76, 0x77};

  for (uint8_t i = 0; i < 2; i++) {
    if (bme.begin(addrs[i], &Wire)) {
      usedAddr = addrs[i];
      return true;
    }
  }
  return false;
}

void setup() {
  Serial.begin(115200);
  delay(1000);  // give the USB serial time to attach

  Serial.println();
  Serial.printf("BME280 check - SDA=%d SCL=%d\n", SDA_PIN, SCL_PIN);

  Wire.begin(SDA_PIN, SCL_PIN);

  scanBus();
  reportChipId(0x76);
  reportChipId(0x77);

  uint8_t addr = 0;
  if (!initSensor(addr)) {
    Serial.println();
    Serial.println("RESULT: sensor not responding on 0x76 or 0x77");
    Serial.println("  1. CSB -> 3V3   (forces I2C mode)");
    Serial.println("  2. SDO -> GND   (fixes the address at 0x76)");
    Serial.println("  3. VCC on 3V3, not 5V");
    return;
  }

  Serial.println();
  Serial.printf("RESULT: sensor OK at 0x%02X\n", addr);

  // Same settings the weather station uses, so a value that reads fine
  // here will read fine there too.
  bme.setSampling(Adafruit_BME280::MODE_FORCED,
                  Adafruit_BME280::SAMPLING_X1,
                  Adafruit_BME280::SAMPLING_X1,
                  Adafruit_BME280::SAMPLING_X1,
                  Adafruit_BME280::FILTER_OFF);

  // Same throwaway conversion as the station firmware - without it the
  // first line below is the power-on default rather than a measurement.
  bme.takeForcedMeasurement();
  delay(10);

  profileSettling();
}

// begin() leaves the sensor in normal mode at 16x oversampling for over
// 100 ms, which warms the die. Forced mode then lets it cool. This traces
// that decay so the station firmware can pick a settle delay that is long
// enough without wasting awake time on battery.
#define PROFILE_SAMPLES  50
#define PROFILE_STEP_MS  100

// Spread of the readings once settled, measured on a quiet bench. A sample
// within this band of the plateau counts as settled.
#define PROFILE_NOISE_C  0.02f

// Averaged into the plateau. Taken from the tail, where the die is cold.
#define PROFILE_TAIL     10

static void profileSettling() {
  static float samples[PROFILE_SAMPLES];

  Serial.println();
  Serial.println("--- settling profile (ms, degC) ---");

  uint32_t start = millis();
  for (uint8_t i = 0; i < PROFILE_SAMPLES; i++) {
    bme.takeForcedMeasurement();
    samples[i] = bme.readTemperature();
    Serial.printf("  %4lu  %.2f\n", millis() - start, samples[i]);
    delay(PROFILE_STEP_MS);
  }

  float plateau = 0.0f;
  for (uint8_t i = PROFILE_SAMPLES - PROFILE_TAIL; i < PROFILE_SAMPLES; i++) {
    plateau += samples[i];
  }
  plateau /= PROFILE_TAIL;

  // First sample that stays inside the noise band for the rest of the run.
  // Checking that it stays put avoids stopping on a single sample that
  // crosses the band on its way down.
  uint8_t settled = PROFILE_SAMPLES;
  for (uint8_t i = 0; i < PROFILE_SAMPLES; i++) {
    bool holds = true;
    for (uint8_t j = i; j < PROFILE_SAMPLES; j++) {
      if (fabsf(samples[j] - plateau) > PROFILE_NOISE_C) {
        holds = false;
        break;
      }
    }
    if (holds) {
      settled = i;
      break;
    }
  }

  Serial.println("--- summary ---");
  Serial.printf("  first reading: %.2f C\n", samples[0]);
  Serial.printf("  plateau:       %.2f C\n", plateau);
  Serial.printf("  offset:        %+.2f C\n", samples[0] - plateau);

  if (settled < PROFILE_SAMPLES) {
    Serial.printf("  settled after: %u ms\n", settled * PROFILE_STEP_MS);
    Serial.printf("  -> use delay(%u) in bme280Begin()\n",
                  settled * PROFILE_STEP_MS);
  } else {
    Serial.printf("  never settled within %.2f C - ambient is drifting\n",
                  PROFILE_NOISE_C);
  }
  Serial.println();
}

void loop() {
  if (!bme.takeForcedMeasurement()) {
    Serial.println("measurement failed");
    delay(2000);
    return;
  }

  float t = bme.readTemperature();
  float h = bme.readHumidity();
  float p = bme.readPressure() / 100.0f;  // Pa -> hPa

  Serial.printf("T=%.2f C  H=%.2f %%  P=%.2f hPa", t, h, p);

  // A dead or mis-wired sensor usually reads a constant or NaN rather
  // than nothing at all, so flag values that cannot be real.
  if (isnan(t) || isnan(h) || isnan(p)) {
    Serial.print("   <- NaN, sensor not usable");
  } else if (t < -40.0f || t > 85.0f || p < 300.0f || p > 1100.0f) {
    Serial.print("   <- out of datasheet range, suspect wiring");
  } else if (h == 0.0f) {
    Serial.print("   <- humidity exactly 0, likely a BMP280");
  }

  Serial.println();
  delay(2000);
}
