/** The hour-of-day chart's periods; `today` draws the day's slots, the others the bias by hour. */
export const PERIODS = ["today", "yesterday", "week", "month"];

export const DEFAULT_PERIOD = "today";

/** Payload: `today` a list of slots, every other period 24 hour rows. */
export function periodRows(payload, period) {
    return payload[PERIODS.includes(period) ? period : DEFAULT_PERIOD] ?? [];
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
