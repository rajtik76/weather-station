#include "noise.h"

#include <math.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include <Arduino.h>
#include <ESP_I2S.h>
#include <dsps_fft2r.h>

#include "station_log.h"
#include "window.h"

// Core 0, away from the loop: core 1 keeps the sensors, the HTTP server and
// the TLS upload, which would otherwise take the CPU from the FFT for
// seconds at a time. On core 0 the task sits below the WiFi driver and
// lwIP (priority 18 and up), so the radio always wins; it sleeps in the I2S
// read and wakes for a few ms of FFT per 64 ms frame, so the idle task
// still runs.
#define NOISE_TASK_CORE 0
#define NOISE_TASK_PRIORITY 5
#define NOISE_TASK_STACK 6144

// Bins below this carry the mic's DC offset and its drift, not sound.
#define NOISE_LOWEST_HZ 20.0f

// One slot is WINDOW_SECONDS long; a level per second.
#define NOISE_MAX_SECONDS WINDOW_SECONDS

#define NO_BAND 0xFF

// I2S is read in pieces; a whole hop at once would need a buffer that size.
#define NOISE_READ_SAMPLES 256

static I2SClass i2s;
static TaskHandle_t task = nullptr;

// On the heap, ~40 kB with the FFT's twiddle table: allocated once in noiseBegin().
static int32_t* raw;          // one I2S read
static float* history;        // the last NOISE_FFT_SIZE samples, full scale = 1
static float* spectrum;       // interleaved re/im for esp-dsp
static float* gainZ;          // per bin: mic correction, power
static float* gainA;          // per bin: mic correction and A-weighting, power
static uint8_t* bandOfBin;

// Converts a frame's summed |X|^2 to mean square: one-sided (x2), Hann power.
static float frameScale;

// Single precision throughout the task: the ESP32's FPU has no double, and
// ~9400 frames a window sum to within 0.01 dB in float.
typedef struct {
  uint32_t slot;
  uint32_t frames;
  float sumA;
  float sumBands[NOISE_BAND_COUNT];
  uint16_t levelCount;
  float levels[NOISE_MAX_SECONDS];  // LAeq,1s in dB
} accumulator_t;

typedef struct {
  bool valid;
  uint32_t slot;
  noise_window_t data;
} finished_t;

static accumulator_t acc;
static float sorted[NOISE_MAX_SECONDS];

static uint32_t secondStamp = 0;
static uint32_t secondFrames = 0;
static float secondSumA = 0;

// Shared with the loop: the finished slot and the counters.
static portMUX_TYPE shared = portMUX_INITIALIZER_UNLOCKED;
static finished_t finished;
static volatile uint32_t openSlot = 0;
static noise_stats_t stats;

// IEC 61672 A-weighting as a power ratio.
static float aWeightPower(float f) {
  const float f2 = f * f;
  const float ra = (12194.0f * 12194.0f * f2 * f2)
                 / ((f2 + 20.6f * 20.6f) * sqrtf((f2 + 107.7f * 107.7f) * (f2 + 737.9f * 737.9f)) * (f2 + 12194.0f * 12194.0f));
  return ra * ra * powf(10.0f, 2.0f / 10.0f);
}

// The INMP441 equalizer from esp32-i2s-slm (ikostoski), a biquad designed at
// 48 kHz, evaluated at f as a power ratio. It lifts what the mic's own
// high-pass takes out: +10 dB at 25 Hz, +1.6 dB at 100 Hz, flat from 500 Hz.
// Double, once at startup: the poles sit so close to the unit circle that
// float loses the low end.
static float micCorrectionPower(float f) {
  const double gain = 1.00197834654696;
  const double b0 = gain, b1 = gain * -1.986920458344451, b2 = gain * 0.986963226946616;
  const double a1 = -1.995178510504166, a2 = 0.995184322194091;
  const double w = 2.0 * M_PI * f / 48000.0;

  // H(e^jw) with z^-1 = cos w - j sin w.
  const double c1 = cos(w), s1 = sin(w), c2 = cos(2 * w), s2 = sin(2 * w);
  const double nr = b0 + b1 * c1 + b2 * c2, ni = -(b1 * s1 + b2 * s2);
  const double dr = 1.0 + a1 * c1 + a2 * c2, di = -(a1 * s1 + a2 * s2);
  return (float)((nr * nr + ni * ni) / (dr * dr + di * di));
}

// Computed per frame rather than kept as a table: the heap is the tight part.
static float hannAt(int i) {
  return 0.5f - 0.5f * cosf(2.0f * (float)M_PI * i / NOISE_FFT_SIZE);
}

static void prepareTables() {
  double hannPower = 0;
  for (int i = 0; i < NOISE_FFT_SIZE; i++) {
    hannPower += (double)hannAt(i) * hannAt(i);
  }
  frameScale = (float)(2.0 / (NOISE_FFT_SIZE * hannPower));

  for (int k = 0; k < NOISE_FFT_SIZE / 2; k++) {
    const float f = (float)k * NOISE_SAMPLE_RATE / NOISE_FFT_SIZE;
    bandOfBin[k] = NO_BAND;

    if (f < NOISE_LOWEST_HZ) {
      gainZ[k] = gainA[k] = 0;
      continue;
    }

    gainZ[k] = micCorrectionPower(f);
    gainA[k] = gainZ[k] * aWeightPower(f);

    // Base-10 third octaves: centre 10^(n/10) kHz, edges a twentieth of a decade either side.
    for (int b = 0; b < NOISE_BAND_COUNT; b++) {
      const float centre = 1000.0f * powf(10.0f, (b - 16) / 10.0f);
      if (f >= centre * powf(10.0f, -0.05f) && f < centre * powf(10.0f, 0.05f)) {
        bandOfBin[k] = (uint8_t)b;
        break;
      }
    }
  }
}

static float meanSquareToDb(float meanSquare) {
  return 10.0f * log10f(meanSquare) + NOISE_DBFS_TO_SPL + MIC_OFFSET_DB;
}

// Hundredths of a dB inside the protocol range. Clamping keeps the order
// between the levels, so the server's checks still hold.
static int16_t toProtocol(float db) {
  if (!isfinite(db)) {
    return NOISE_LEVEL_MIN;
  }
  long v = lroundf(db * 100.0f);
  if (v < NOISE_LEVEL_MIN) v = NOISE_LEVEL_MIN;
  if (v > NOISE_LEVEL_MAX) v = NOISE_LEVEL_MAX;
  return (int16_t)v;
}

static int compareFloat(const void* a, const void* b) {
  const float x = *(const float*)a, y = *(const float*)b;
  return (x > y) - (x < y);
}

static void resetAccumulator(uint32_t slot) {
  memset(&acc, 0, sizeof(acc));
  acc.slot = slot;
  openSlot = slot;
}

static void closeSecond() {
  if (secondFrames > 0 && acc.levelCount < NOISE_MAX_SECONDS) {
    acc.levels[acc.levelCount++] = meanSquareToDb(secondSumA / secondFrames);
  }
  secondFrames = 0;
  secondSumA = 0;
}

static void finishSlot() {
  if (acc.frames == 0 || acc.levelCount == 0) {
    return;
  }

  noise_window_t out;
  out.seconds = acc.levelCount;
  out.laeq = toProtocol(meanSquareToDb(acc.sumA / acc.frames));

  // Nearest rank on the one-second levels: L90 is the level 90 % of the
  // seconds exceed, so it sits low in the ascending order.
  memcpy(sorted, acc.levels, sizeof(float) * acc.levelCount);
  qsort(sorted, acc.levelCount, sizeof(float), compareFloat);
  const uint16_t last = acc.levelCount - 1;
  out.lamax = toProtocol(sorted[last]);
  out.la10 = toProtocol(sorted[(last * 90 + 50) / 100]);
  out.la90 = toProtocol(sorted[(last * 10 + 50) / 100]);

  for (int b = 0; b < NOISE_BAND_COUNT; b++) {
    out.bands[b] = toProtocol(meanSquareToDb(acc.sumBands[b] / acc.frames));
  }

  taskENTER_CRITICAL(&shared);
  finished.valid = true;
  finished.slot = acc.slot;
  finished.data = out;
  taskEXIT_CRITICAL(&shared);
}

// One frame over the last NOISE_FFT_SIZE samples. False when there was no
// signal at all.
static bool analyseFrame(float& sumA, float sumBands[NOISE_BAND_COUNT]) {
  float mean = 0;
  for (int i = 0; i < NOISE_FFT_SIZE; i++) {
    mean += history[i];
  }
  mean /= NOISE_FFT_SIZE;

  for (int i = 0; i < NOISE_FFT_SIZE; i++) {
    spectrum[2 * i] = (history[i] - mean) * hannAt(i);
    spectrum[2 * i + 1] = 0;
  }

  dsps_fft2r_fc32(spectrum, NOISE_FFT_SIZE);
  dsps_bit_rev_fc32(spectrum, NOISE_FFT_SIZE);

  sumA = 0;
  memset(sumBands, 0, sizeof(float) * NOISE_BAND_COUNT);
  for (int k = 1; k < NOISE_FFT_SIZE / 2; k++) {
    const float power = spectrum[2 * k] * spectrum[2 * k] + spectrum[2 * k + 1] * spectrum[2 * k + 1];
    sumA += power * gainA[k];
    if (bandOfBin[k] != NO_BAND) {
      sumBands[bandOfBin[k]] += power * gainZ[k];
    }
  }

  return sumA > 0;
}

static void noiseTask(void*) {
  const uint32_t startedMs = millis();
  float frameBands[NOISE_BAND_COUNT];

  while (true) {
    // A short read drops the hop; the history stays contiguous.
    bool complete = true;
    memmove(history, history + NOISE_HOP, sizeof(float) * (NOISE_FFT_SIZE - NOISE_HOP));
    for (int filled = 0; filled < NOISE_HOP && complete; filled += NOISE_READ_SAMPLES) {
      const size_t got = i2s.readBytes((char*)raw, sizeof(int32_t) * NOISE_READ_SAMPLES);
      if (got != sizeof(int32_t) * NOISE_READ_SAMPLES) {
        complete = false;
        break;
      }

      // 24-bit sample, left-aligned in the 32-bit slot, scaled to full scale 1.
      for (int i = 0; i < NOISE_READ_SAMPLES; i++) {
        history[NOISE_FFT_SIZE - NOISE_HOP + filled + i] = (raw[i] >> 8) / 8388608.0f;
      }
    }
    if (!complete) {
      stats.short_reads++;
      continue;
    }

    // A frame without a stamp cannot be filed into a slot.
    if (millis() - startedMs < NOISE_WARMUP_MS || !stationClockIsSet()) {
      continue;
    }

    float frameA;
    if (!analyseFrame(frameA, frameBands)) {
      stats.silent_frames++;
      continue;
    }

    const uint32_t now = (uint32_t)time(nullptr);
    if (now != secondStamp) {
      closeSecond();
      secondStamp = now;
    }

    const uint32_t slot = windowSlotOf(now);
    if (slot != acc.slot) {
      finishSlot();
      resetAccumulator(slot);
    }

    const float meanSquareA = frameA * frameScale;
    secondSumA += meanSquareA;
    secondFrames++;
    acc.sumA += meanSquareA;
    for (int b = 0; b < NOISE_BAND_COUNT; b++) {
      acc.sumBands[b] += frameBands[b] * frameScale;
    }
    acc.frames++;
    stats.frames++;
  }
}

bool noiseBegin() {
  raw = (int32_t*)malloc(sizeof(int32_t) * NOISE_READ_SAMPLES);
  history = (float*)calloc(NOISE_FFT_SIZE, sizeof(float));
  spectrum = (float*)malloc(sizeof(float) * NOISE_FFT_SIZE * 2);
  gainZ = (float*)malloc(sizeof(float) * NOISE_FFT_SIZE / 2);
  gainA = (float*)malloc(sizeof(float) * NOISE_FFT_SIZE / 2);
  bandOfBin = (uint8_t*)malloc(NOISE_FFT_SIZE / 2);

  if (!raw || !history || !spectrum || !gainZ || !gainA || !bandOfBin) {
    logInfo("noise: out of memory, running without the microphone");
    return false;
  }

  if (dsps_fft2r_init_fc32(nullptr, NOISE_FFT_SIZE) != ESP_OK) {
    logInfo("noise: FFT init failed, running without the microphone");
    return false;
  }

  prepareTables();
  resetAccumulator(0);

  i2s.setPins(NOISE_SCK_PIN, NOISE_WS_PIN, -1, NOISE_SD_PIN);
  if (!i2s.begin(I2S_MODE_STD, NOISE_SAMPLE_RATE, I2S_DATA_BIT_WIDTH_32BIT, I2S_SLOT_MODE_MONO, I2S_STD_SLOT_LEFT)) {
    logInfo("noise: I2S did not start, running without the microphone");
    return false;
  }

  if (xTaskCreatePinnedToCore(noiseTask, "noise", NOISE_TASK_STACK, nullptr, NOISE_TASK_PRIORITY, &task, NOISE_TASK_CORE) != pdPASS) {
    logInfo("noise: task did not start, running without the microphone");
    return false;
  }

  stats.running = true;
  logInfo("noise: INMP441 at %d Hz, %d-point FFT, trim %+.1f dB", NOISE_SAMPLE_RATE, NOISE_FFT_SIZE, MIC_OFFSET_DB);
  return true;
}

bool noiseTake(uint32_t slot, noise_window_t& out, uint32_t waitMs) {
  const uint32_t start = millis();

  while (true) {
    bool found = false;

    taskENTER_CRITICAL(&shared);
    if (finished.valid && finished.slot == slot) {
      out = finished.data;
      finished.valid = false;
      found = true;
    }
    taskEXIT_CRITICAL(&shared);

    // Only a slot the task is still filling is worth waiting for.
    if (found || !stats.running || openSlot != slot || millis() - start >= waitMs) {
      return found;
    }
    delay(20);
  }
}

noise_stats_t noiseStats() {
  return stats;
}

void noisePause() {
  if (task != nullptr) {
    vTaskSuspend(task);
  }
}

// The history now spans the pause; the slot it lands in starts over.
void noiseResume() {
  if (task != nullptr) {
    vTaskResume(task);
  }
}
