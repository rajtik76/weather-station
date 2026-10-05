/** Log axis has no zero; a dark night reads 0 lx. */
export const LIGHT_FLOOR = 0.01;

export const LIGHT_SCALE = { log: "log", linear: "linear" };

export function lightAxis(scale) {
    return scale === LIGHT_SCALE.linear
        ? { type: "value", min: 0 }
        : { type: "log", min: LIGHT_FLOOR };
}

export function plottedLux(value, scale) {
    return value === null || scale === LIGHT_SCALE.linear ? value : Math.max(value, LIGHT_FLOOR);
}
