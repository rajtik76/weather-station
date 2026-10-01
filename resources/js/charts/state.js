/**
 * What the strips share, mutated in place: an ES module cannot reassign an
 * imported binding, so every shared value is a property of this one object.
 * `render()` in station-charts.js refills it from the payload on each paint.
 */
export const state = {
    /** Strip rows (see COLUMN in rows.js). */
    rows: [],
    /** Event rows (see EVENT in rows.js). */
    events: [],
    /** Noise rows (see NOISE_COLUMN in rows.js). */
    noiseRows: [],
    /** Light rows (see LIGHT_COLUMN in rows.js). */
    lightRows: [],
    /** `[wall-clock ms, epoch]` of the noise slots the server heard rain in (Charts::rainSlots). */
    rainSlots: [],
    /** The navigator's rows, which always span the whole record. */
    overview: [],
    /** Hidden channels, as the server's payload states them so the choice survives a poll. */
    hidden: new Set(),
    /** typicalStep() per strip key, worked out once a render; the tooltip reads it on every pointer move. */
    stripSteps: new Map(),
    /**
     * The event line under the pointer. Event lines have no tooltip of their
     * own: an item tooltip would drop the readings and the crosshair. The axis
     * tooltip reads this instead.
     */
    hovered: null,
};

/** The ECharts instance of every mounted strip, by strip key. */
export const charts = new Map();
