#ifndef NOISE_H
#define NOISE_H

#include <stdint.h>

#include "reading_types.h"

// Must match the base board: D26 = SCK, D25 = WS, D33 = SD. L/R is strapped
// to GND, so the mic talks in the left slot.
#define NOISE_SCK_PIN 26
#define NOISE_WS_PIN 25
#define NOISE_SD_PIN 33

// Nyquist is 8 kHz, so the top band (7.1 - 8.9 kHz) only sees its lower half.
#define NOISE_SAMPLE_RATE 16000

// 7.8 Hz bins; 4096 points would need ~100 kB and leave TLS no heap.
// Hann windows, half overlapping: a frame every 64 ms.
#define NOISE_FFT_SIZE 2048
#define NOISE_HOP (NOISE_FFT_SIZE / 2)

// dB SPL = dBFS + 120 (datasheet: -26 dBFS at 94 dB SPL); trim matched against a phone SLM.
#define NOISE_DBFS_TO_SPL 120.0f
#define MIC_OFFSET_DB -15.0f

// The mic is muted for ~85 ms after the clock starts and its DC offset
// settles for a few seconds after power-up.
#define NOISE_WARMUP_MS 3000

typedef struct {
  bool running;             // I2S started and the task is up
  uint32_t frames;          // FFT frames that went into a window
  uint32_t silent_frames;   // frames with no signal at all: SD stuck low or high
  uint32_t short_reads;     // I2S reads that came back short
} noise_stats_t;

// False when the driver did not start; the station runs on without noise.
bool noiseBegin();

// Summary of a finished slot, once. Waits up to waitMs for a slot still open. False when the mic gave nothing.
bool noiseTake(uint32_t slot, noise_window_t& out, uint32_t waitMs);

noise_stats_t noiseStats();

// Around an upload or OTA: parks the task and frees its ~40 kB for TLS. The open slot misses the gap.
void noisePause();
void noiseResume();

// Around every I2C read: parks the task and stops SCK/WS, buffers stay. The clocks share the
// 4 m cable with SDA/SCL and the VEML7700 missed every other transfer. ~0.5 s of noise lost per reading.
void noiseHush();
void noiseUnhush();

// Retry interval after a resume that found no memory.
#define NOISE_RETRY_MS 60000

#endif
