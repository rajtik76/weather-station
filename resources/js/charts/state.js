/** Shared values are properties of one object: an ES module cannot reassign an imported binding. Refilled by `render()` on each paint. */
export const state = {
    /** Strip rows (see COLUMN in rows.js). */
    rows: [],
    /** Event rows (see EVENT in rows.js). */
    events: [],
    /** Noise rows (see NOISE_COLUMN in rows.js). */
    noiseRows: [],
    /** Light rows (see LIGHT_COLUMN in rows.js). */
    lightRows: [],
    /** `[wall-clock ms, epoch]` of the noise slots with rain. */
    rainSlots: [],
    /** The navigator's rows, which always span the whole record. */
    overview: [],
    hidden: new Set(),
    /** typicalStep() per strip key; the tooltip reads it on every pointer move. */
    stripSteps: new Map(),
    /** The event line under the pointer: an item tooltip would drop the readings and the crosshair, so the axis tooltip reads this. */
    hovered: null,
};

/** ECharts instance per mounted strip key. */
export const charts = new Map();
