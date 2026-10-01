import { state } from "./state";

/**
 * Row layout from the server: wall-clock ms, °C, %, hPa, dew point, epoch,
 * then a min-max pair per channel on strip rows only. A missed slot is nulls.
 */
export const COLUMN = {
    time: 0,
    t: 1,
    h: 2,
    p: 3,
    d: 4,
    epoch: 5,
    tMin: 6,
    tMax: 7,
    hMin: 8,
    hMax: 9,
    pMin: 10,
    pMax: 11,
};

/**
 * Noise row from the server: wall-clock ms, epoch, LAeq, LA10, LA90, LAmax,
 * then the 26 third-octave bands, all dB. A slot without noise is nulls.
 */
export const NOISE_COLUMN = { time: 0, epoch: 1, laeq: 2, la10: 3, la90: 4, lamax: 5, band: 6 };

/** Light row from the server: wall-clock ms, epoch, mean, min, max, all lx. A slot without light is nulls. */
export const LIGHT_COLUMN = { time: 0, epoch: 1, mean: 2, min: 3, max: 4 };

/** Event row: wall-clock ms, title, CSS colour or null. */
export const EVENT = { time: 0, title: 1, colour: 2 };

export function nearestRow(list, time, column = COLUMN.time) {
    if (list.length === 0) {
        return null;
    }

    let low = 0;
    let high = list.length - 1;

    while (low < high) {
        const mid = (low + high) >> 1;

        if (list[mid][column] < time) {
            low = mid + 1;
        } else {
            high = mid;
        }
    }

    const after = list[low];
    const before = list[Math.max(0, low - 1)];

    return Math.abs(before[column] - time) <= Math.abs(after[column] - time) ? before : after;
}

export function rowAt(time) {
    return nearestRow(state.rows, time);
}

/** Wall-clock ms back to a real epoch. The offset changes with DST, so it is read off the nearest row, which carries both. */
export function epochFromWallMs(list, milliseconds) {
    const row = nearestRow(list, milliseconds);

    if (!row) {
        return null;
    }

    const offsetSeconds = Math.round(row[COLUMN.time] / 1000) - row[COLUMN.epoch];

    return Math.round(milliseconds / 1000) - offsetSeconds;
}

/** Median spacing between rows, to tell a gap from a step. Read off the rows because the bucket width varies with the window. */
export function typicalStep(list, column = COLUMN.time) {
    if (list.length < 2) {
        return 0;
    }

    const gaps = [];

    for (let index = 1; index < list.length; index++) {
        gaps.push(list[index][column] - list[index - 1][column]);
    }

    gaps.sort((left, right) => left - right);

    return gaps[gaps.length >> 1];
}
