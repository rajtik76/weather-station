// Bench check of all four sensors; a missing one is retried every pass.

#include <Wire.h>
#include <ESP_I2S.h>
#include <driver/gpio.h>
#include <Adafruit_BMP280.h>
#include <Adafruit_SHT4x.h>

// Must match the base board: D21 = SDA, D22 = SCL, D26 = SCK, D25 = WS, D33 = SD.
#define SDA_PIN 21
#define SCL_PIN 22
#define I2S_SCK_PIN 26
#define I2S_WS_PIN 25
#define I2S_SD_PIN 33

#define BMP280_ADDR 0x76
#define VEML7700_ADDR 0x10

// VEML7700 registers, driven raw.
#define VEML7700_REG_CONF 0x00
#define VEML7700_REG_ALS 0x04
#define VEML7700_REG_WHITE 0x05

// Least sensitive setting (gain 1/8, 25 ms): full sun does not saturate (~140 klx).
#define VEML7700_CONF_GAIN_1_8_IT_25MS 0x1300
#define VEML7700_LUX_PER_COUNT 2.1504f
#define VEML7700_IT_MS 25
#define SAMPLE_RATE 16000
#define FRAMES_PER_READ 1024

// The mic is muted for 2^18 SCK cycles after the clock starts: 256 ms at 1.024 MHz.
#define WARMUP_MS 300

#define PASS_MS 2000

// As in the station firmware: the 4 m cable.
#define I2C_CLOCK_HZ 20000

static Adafruit_BMP280 bmp(&Wire);
static Adafruit_SHT4x sht;
static I2SClass i2s;
static int32_t frames[FRAMES_PER_READ * 2];

static bool bmpReady = false;
static bool shtReady = false;
static bool vemlReady = false;
static bool i2sReady = false;

static void scanBus() {
  Serial.print("I2C scan:");

  uint8_t found = 0;
  for (uint8_t addr = 1; addr < 127; addr++) {
    Wire.beginTransmission(addr);
    if (Wire.endTransmission() == 0) {
      Serial.printf(" 0x%02X", addr);
      found++;
    }
  }

  Serial.println(found == 0 ? " nothing" : "  (expect 0x10 VEML7700, 0x44 SHT4x, 0x76 BMP280)");
}

static bool bmpBegin() {
  if (!bmp.begin(BMP280_ADDR)) return false;

  bmp.setSampling(Adafruit_BMP280::MODE_FORCED,
                  Adafruit_BMP280::SAMPLING_X1,
                  Adafruit_BMP280::SAMPLING_X1,
                  Adafruit_BMP280::FILTER_OFF);
  return true;
}

static bool shtBegin() {
  if (!sht.begin(&Wire)) return false;

  sht.setPrecision(SHT4X_HIGH_PRECISION);
  sht.setHeater(SHT4X_NO_HEATER);
  return true;
}

static void reportBmp() {
  if (!bmpReady) bmpReady = bmpBegin();
  if (!bmpReady) {
    Serial.print("BMP280 --- no answer          ");
    return;
  }

  if (!bmp.takeForcedMeasurement()) {
    Serial.print("BMP280 --- measurement failed ");
    bmpReady = false;
    return;
  }

  Serial.printf("BMP280 %6.2f C %7.2f hPa  ", bmp.readTemperature(), bmp.readPressure() / 100.0f);
}

static void reportSht() {
  if (!shtReady) shtReady = shtBegin();
  if (!shtReady) {
    Serial.print("| SHT4x --- no answer         ");
    return;
  }

  sensors_event_t humidity, temp;
  if (!sht.getEvent(&humidity, &temp)) {
    Serial.print("| SHT4x --- read failed       ");
    shtReady = false;
    return;
  }

  Serial.printf("| SHT4x %6.2f C %6.2f %%  ", temp.temperature, humidity.relative_humidity);
}

static bool vemlWrite(uint8_t reg, uint16_t value) {
  Wire.beginTransmission(VEML7700_ADDR);
  Wire.write(reg);
  Wire.write(value & 0xFF);
  Wire.write(value >> 8);
  return Wire.endTransmission() == 0;
}

static bool vemlRead(uint8_t reg, uint16_t& value) {
  Wire.beginTransmission(VEML7700_ADDR);
  Wire.write(reg);
  if (Wire.endTransmission(false) != 0) return false;
  if (Wire.requestFrom(VEML7700_ADDR, 2) != 2) return false;

  value = Wire.read();
  value |= Wire.read() << 8;
  return true;
}

static bool vemlBegin() {
  if (!vemlWrite(VEML7700_REG_CONF, VEML7700_CONF_GAIN_1_8_IT_25MS)) return false;

  delay(VEML7700_IT_MS * 2 + 5);  // power-up plus one integration
  return true;
}

static void reportVeml() {
  if (!vemlReady) vemlReady = vemlBegin();
  if (!vemlReady) {
    Serial.print("| VEML7700 --- no answer      ");
    return;
  }

  uint16_t als, white;
  if (!vemlRead(VEML7700_REG_ALS, als) || !vemlRead(VEML7700_REG_WHITE, white)) {
    Serial.print("| VEML7700 --- read failed    ");
    vemlReady = false;
    return;
  }

  Serial.printf("| VEML7700 %8.1f lx (als %5u white %5u)  ", als * VEML7700_LUX_PER_COUNT, als, white);
}

// Samples are 24-bit, left-aligned in 32. AC rms of one slot, -1 when dead (constant).
static double slotRms(size_t slot, size_t count) {
  int32_t min = INT32_MAX;
  int32_t max = INT32_MIN;
  double sum = 0;

  for (size_t i = 0; i < count; i++) {
    const int32_t v = frames[i * 2 + slot] >> 8;
    if (v < min) min = v;
    if (v > max) max = v;
    sum += v;
  }
  if (min == max) return -1;

  const double mean = sum / count;
  double sq = 0;
  for (size_t i = 0; i < count; i++) {
    const double ac = (frames[i * 2 + slot] >> 8) - mean;
    sq += ac * ac;
  }
  return sqrt(sq / count);
}

static void reportMic() {
  if (!i2sReady) {
    Serial.print("| INMP441 --- I2S driver failed");
    return;
  }

  const size_t got = i2s.readBytes((char*)frames, sizeof(frames));
  const size_t count = got / (2 * sizeof(int32_t));
  if (count == 0) {
    Serial.print("| INMP441 --- no data from driver");
    return;
  }

  const double left = slotRms(0, count);
  const double right = slotRms(1, count);

  if (left >= 0 && right < 0) {
    Serial.printf("| INMP441 %6.1f dBFS", 20.0 * log10(left / 8388608.0));
  } else if (left < 0 && right >= 0) {
    Serial.print("| INMP441 --- data in RIGHT slot, strap L/R to GND");
  } else if (left >= 0 && right >= 0) {
    Serial.print("| INMP441 --- data in both slots, SD is noise (mic not driving it)");
  } else {
    Serial.print("| INMP441 --- all zero, no mic");
  }
}

void setup() {
  Serial.begin(115200);
  delay(1000);  // USB serial attach

  Serial.println();
  Serial.printf("Sensors check - I2C SDA=%d SCL=%d, I2S SCK=%d WS=%d SD=%d\n",
                SDA_PIN, SCL_PIN, I2S_SCK_PIN, I2S_WS_PIN, I2S_SD_PIN);

  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setClock(I2C_CLOCK_HZ);
  scanBus();

  i2s.setPins(I2S_SCK_PIN, I2S_WS_PIN, -1, I2S_SD_PIN);
  i2sReady = i2s.begin(I2S_MODE_STD, SAMPLE_RATE, I2S_DATA_BIT_WIDTH_32BIT, I2S_SLOT_MODE_STEREO);
  if (i2sReady) {
    // As in the station firmware: weakest drive on the clocks that share the cable with I2C.
    gpio_set_drive_capability((gpio_num_t)I2S_SCK_PIN, GPIO_DRIVE_CAP_0);
    gpio_set_drive_capability((gpio_num_t)I2S_WS_PIN, GPIO_DRIVE_CAP_0);
    delay(WARMUP_MS);
    i2s.readBytes((char*)frames, sizeof(frames));  // drop the muted start
  }
}

void loop() {
  const uint32_t start = millis();

  // I2C only with the mic clocks stopped (noiseHush() in the station firmware).
  if (i2sReady) i2s_channel_disable(i2s.rxChan());
  reportBmp();
  reportSht();
  reportVeml();
  if (i2sReady) {
    i2s_channel_enable(i2s.rxChan());
    delay(WARMUP_MS);
    i2s.readBytes((char*)frames, sizeof(frames));  // drop stale DMA data and the muted start
  }
  reportMic();
  Serial.println();

  const uint32_t spent = millis() - start;
  if (spent < PASS_MS) delay(PASS_MS - spent);
}
