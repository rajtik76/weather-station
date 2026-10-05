/** `today` draws the day's slots, every other period the bias by hour. */
export const DEFAULT_PERIOD = "today";

/** Payload: `today` a list of slots, every other period (ScorePeriod) 24 hour rows. */
export function periodRows(payload, period) {
    return payload[period] ?? payload[DEFAULT_PERIOD] ?? [];
}

/** A value with no neighbour on either side gets its own marker: a line needs two points. */
export function markLonePoints(values) {
    return values.map((value, index) =>
        value !== null &&
        (values[index - 1] ?? null) === null &&
        (values[index + 1] ?? null) === null
            ? { value, symbol: "circle", symbolSize: 5 }
            : value,
    );
}

/** Slot: `clock`, `measured`, `shown` band or null, `base` and `experiment` medians or null, in °C. */
export function todaySeries(slots) {
    return {
        clocks: slots.map((slot) => slot.clock),
        measured: slots.map((slot) => slot.measured),
        shown: slots.map((slot) => slot.shown?.mid ?? null),
        shownLow: slots.map((slot) => slot.shown?.low ?? null),
        shownSpread: slots.map((slot) =>
            slot.shown === null ? null : slot.shown.high - slot.shown.low,
        ),
        base: slots.map((slot) => slot.base),
        experiment: slots.map((slot) => slot.experiment),
    };
}

export const hasValues = (values) => values.some((value) => value !== null);
