#include "noise.h"

#include <math.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include <Arduino.h>
#include <ESP_I2S.h>
#include <driver/gpio.h>
#include <dsps_fft2r.h>

#include "station_log.h"
#include "window.h"

// Core 0: core 1 runs sensors, HTTP and TLS, which would starve the FFT. Priority below the WiFi driver and lwIP (18+).
#define NOISE_TASK_CORE 0
#define NOISE_TASK_PRIORITY 5
#define NOISE_TASK_STACK 6144

// Bins below this carry the mic's DC offset and its drift, not sound.
#define NOISE_LOWEST_HZ 20.0f

#define NOISE_MAX_SECONDS WINDOW_SECONDS

#define NO_BAND 0xFF

#define NOISE_READ_SAMPLES 256

// Hops dropped after wake: 2 refill the history; after a clock stop the mic is muted
// 2^18 SCK cycles (256 ms at 1.024 MHz), 4 more hops.
#define NOISE_HOPS_AFTER_PAUSE 2
#define NOISE_HOPS_AFTER_HUSH 6

#define NOISE_PARK_POLL_MS 100

static I2SClass i2s;
static TaskHandle_t task = nullptr;

// Heap, ~40 kB with the twiddle table; freed around every upload, mbedTLS fails to allocate
// with a 36 kB largest free block.
static int32_t* raw;          // one I2S read
static float* history;        // the last NOISE_FFT_SIZE samples, full scale = 1
static float* spectrum;       // interleaved re/im for esp-dsp
static float* gainZ;          // per bin: mic correction, power
static float* gainA;          // per bin: mic correction and A-weighting, power
static uint8_t* bandOfBin;

// Converts a frame's summed |X|^2 to mean square: one-sided (x2), Hann power.
static float frameScale;

// Float only in the task: the ESP32's FPU has no double.
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

// The loop asks, the task parks and says so; only then are the buffers freed.
static volatile bool pauseRequested = false;
static volatile bool paused = false;
static bool buffersAllocated = false;
static volatile uint8_t hopsAfterWake = NOISE_HOPS_AFTER_PAUSE;

static bool hushParked = false;
static bool clockStopped = false;

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

// INMP441 equalizer from esp32-i2s-slm (ikostoski): a 48 kHz biquad evaluated at f, power ratio.
// Double: the poles sit so close to the unit circle that float loses the low end.
static float micCorrectionPower(float f) {
  const double gain = 1.00197834654696;
  const double b0 = gain, b1 = gain * -1.986920458344451, b2 = gain * 0.986963226946616;
  const double a1 = -1.995178510504166, a2 = 0.995184322194091;
  const double w = 2.0 * M_PI * f / 48000.0;

  const double c1 = cos(w), s1 = sin(w), c2 = cos(2 * w), s2 = sin(2 * w);
  const double nr = b0 + b1 * c1 + b2 * c2, ni = -(b1 * s1 + b2 * s2);
  const double dr = 1.0 + a1 * c1 + a2 * c2, di = -(a1 * s1 + a2 * s2);
  return (float)((nr * nr + ni * ni) / (dr * dr + di * di));
}

// Not a table: the heap is tight.
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

// 0.01 dB, clamped to the protocol range; clamping keeps the order the server checks.
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

  // Nearest rank on the one-second levels; L90 is exceeded 90 % of the time, so it sits low.
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

// False when there was no signal at all.
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

  uint8_t hopsToRefill = NOISE_HOPS_AFTER_PAUSE;

  while (true) {
    if (pauseRequested) {
      // Re-check the flag, not one notification: a waker may have cleared it before `paused = true` and sent none.
      paused = true;
      while (pauseRequested) {
        ulTaskNotifyTake(pdTRUE, pdMS_TO_TICKS(NOISE_PARK_POLL_MS));
      }
      paused = false;
      hopsToRefill = hopsAfterWake;
      hopsAfterWake = NOISE_HOPS_AFTER_PAUSE;
      continue;
    }

    // A short read drops the hop.
    bool complete = true;
    memmove(history, history + NOISE_HOP, sizeof(float) * (NOISE_FFT_SIZE - NOISE_HOP));
    for (int filled = 0; filled < NOISE_HOP && complete; filled += NOISE_READ_SAMPLES) {
      const size_t got = i2s.readBytes((char*)raw, sizeof(int32_t) * NOISE_READ_SAMPLES);
      if (got != sizeof(int32_t) * NOISE_READ_SAMPLES) {
        complete = false;
        break;
      }

      // 24-bit sample left-aligned in 32 bits.
      for (int i = 0; i < NOISE_READ_SAMPLES; i++) {
        history[NOISE_FFT_SIZE - NOISE_HOP + filled + i] = (raw[i] >> 8) / 8388608.0f;
      }
    }
    if (!complete) {
      stats.short_reads++;
      continue;
    }

    if (hopsToRefill > 0) {
      hopsToRefill--;
      continue;
    }

    // A frame without a stamp cannot be filed.
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

static void releaseBuffers() {
  free(raw);
  free(history);
  free(spectrum);
  free(gainZ);
  free(gainA);
  free(bandOfBin);
  raw = nullptr;
  history = nullptr;
  spectrum = nullptr;
  gainZ = gainA = nullptr;
  bandOfBin = nullptr;
  dsps_fft2r_deinit_fc32();
  buffersAllocated = false;
}

static bool allocateBuffers() {
  raw = (int32_t*)malloc(sizeof(int32_t) * NOISE_READ_SAMPLES);
  history = (float*)calloc(NOISE_FFT_SIZE, sizeof(float));
  spectrum = (float*)malloc(sizeof(float) * NOISE_FFT_SIZE * 2);
  gainZ = (float*)malloc(sizeof(float) * NOISE_FFT_SIZE / 2);
  gainA = (float*)malloc(sizeof(float) * NOISE_FFT_SIZE / 2);
  bandOfBin = (uint8_t*)malloc(NOISE_FFT_SIZE / 2);

  if (!raw || !history || !spectrum || !gainZ || !gainA || !bandOfBin
      || dsps_fft2r_init_fc32(nullptr, NOISE_FFT_SIZE) != ESP_OK) {
    releaseBuffers();
    return false;
  }

  prepareTables();
  buffersAllocated = true;
  return true;
}

bool noiseBegin() {
  if (!allocateBuffers()) {
    logInfo("noise: out of memory, running without the microphone");
    return false;
  }

  resetAccumulator(0);

  i2s.setPins(NOISE_SCK_PIN, NOISE_WS_PIN, -1, NOISE_SD_PIN);
  // Without the task nothing gives the buffers back for TLS.
  if (!i2s.begin(I2S_MODE_STD, NOISE_SAMPLE_RATE, I2S_DATA_BIT_WIDTH_32BIT, I2S_SLOT_MODE_MONO, I2S_STD_SLOT_LEFT)) {
    releaseBuffers();
    logInfo("noise: I2S did not start, running without the microphone");
    return false;
  }

  // Weakest drive: the clocks run 4 m next to the I2C pairs.
  gpio_set_drive_capability((gpio_num_t)NOISE_SCK_PIN, GPIO_DRIVE_CAP_0);
  gpio_set_drive_capability((gpio_num_t)NOISE_WS_PIN, GPIO_DRIVE_CAP_0);

  if (xTaskCreatePinnedToCore(noiseTask, "noise", NOISE_TASK_STACK, nullptr, NOISE_TASK_PRIORITY, &task, NOISE_TASK_CORE) != pdPASS) {
    task = nullptr;
    i2s.end();
    releaseBuffers();
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

    if (found || !stats.running || openSlot != slot || millis() - start >= waitMs) {
      return found;
    }
    delay(20);
  }
}

noise_stats_t noiseStats() {
  return stats;
}

#define NOISE_PAUSE_WAIT_MS 1000

void noisePause() {
  if (task == nullptr || !buffersAllocated) {
    return;
  }

  pauseRequested = true;
  const uint32_t start = millis();
  while (!paused && millis() - start < NOISE_PAUSE_WAIT_MS) {
    delay(5);
  }

  // Freeing under a task mid-frame would corrupt the heap.
  if (!paused) {
    logInfo("noise: task did not park, keeping its memory");
    return;
  }

  releaseBuffers();
}

void noiseResume() {
  if (task == nullptr || !pauseRequested) {
    return;
  }

  if (!buffersAllocated && !allocateBuffers()) {
    stats.running = false;
    logInfo("noise: no memory to resume, largest free block %u", (unsigned)ESP.getMaxAllocHeap());
    return;
  }

  stats.running = true;
  pauseRequested = false;

  // A task that never parked is still running: no notification to bank.
  if (paused) {
    xTaskNotifyGive(task);
  }
}

// Parks like noisePause(), then stops the channel (SCK and WS). Buffers stay: freeing 40 kB
// twice a minute would fragment the heap. A task parked by a failed resume is left to noiseResume().
void noiseHush() {
  if (task == nullptr) {
    return;
  }

  if (!pauseRequested) {
    hushParked = true;
    pauseRequested = true;
    const uint32_t start = millis();
    while (!paused && millis() - start < NOISE_PAUSE_WAIT_MS) {
      delay(5);
    }
  }

  // Only a parked task is safe: a blocked read would time out short.
  if (!paused) {
    logInfo("noise: task did not park, clock keeps running");
    return;
  }

  clockStopped = i2s_channel_disable(i2s.rxChan()) == ESP_OK;
}

void noiseUnhush() {
  // Only a stopped clock mutes the mic.
  const bool restarted = clockStopped;
  if (clockStopped) {
    i2s_channel_enable(i2s.rxChan());
    clockStopped = false;
  }

  if (!hushParked) {
    return;
  }
  hushParked = false;
  hopsAfterWake = restarted ? NOISE_HOPS_AFTER_HUSH : NOISE_HOPS_AFTER_PAUSE;
  pauseRequested = false;

  if (paused) {
    xTaskNotifyGive(task);
  }
}
