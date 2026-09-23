#ifndef NOISE_H
#define NOISE_H

#include <stdint.h>

#include "reading_types.h"

// The INMP441 outside, over I2S: a task of its own turns the samples into
// third-octave bands and A-weighted levels and hands one summary per window
// slot to the loop. It follows the wall clock, so its slots are the same
// epoch slots the readings go into (windowSlotOf()).

// Must match the base board: D26 = SCK, D25 = WS, D33 = SD. L/R is strapped
// to GND, so the mic talks in the left slot.
#define NOISE_SCK_PIN 26
#define NOISE_WS_PIN 25
#define NOISE_SD_PIN 33

// 16 kHz ran next to the I2C sensors over the 4 m cable without a glitch.
// Nyquist is 8 kHz, so the top band (7.1 - 8.9 kHz) only sees its lower half.
#define NOISE_SAMPLE_RATE 16000

// 2048 points: 7.8 Hz bins, one each for the 25 - 40 Hz bands. 4096 would
// resolve them better but its ~100 kB left the TLS upload no heap.
// Hann windows, half overlapping: a frame every 64 ms.
#define NOISE_FFT_SIZE 2048
#define NOISE_HOP (NOISE_FFT_SIZE / 2)

// dB SPL = dBFS + 120 from the datasheet (-26 dBFS at 94 dB SPL); the trim
// matched this module against a phone SLM (2026-09-22).
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

// Starts I2S and the task. False when the driver did not start; the station
// runs on without noise.
bool noiseBegin();

// The summary of a finished slot, once. The task closes a slot on its first
// frame past the boundary, so this waits up to waitMs for a slot still open.
// False when the mic gave nothing for it.
bool noiseTake(uint32_t slot, noise_window_t& out, uint32_t waitMs);

noise_stats_t noiseStats();

// Around an upload or an OTA update: the task parks and its ~40 kB go back
// to the heap for TLS, then are allocated again. The slot being filled
// misses the seconds in between.
void noisePause();
void noiseResume();

#endif
