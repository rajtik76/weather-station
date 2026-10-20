import { COMPARISON_COLUMN } from "../rows";
import { difference, differenceStrip } from "./difference";

/** |Mikulka − in use| − |Mikulka − retrained|, °C; positive = retrained closer. */
export function improvement(row) {
    const current = difference(row, COMPARISON_COLUMN.estimated);
    const retrained = difference(row, COMPARISON_COLUMN.candidate);

    return current === null || retrained === null ? null : Math.abs(current) - Math.abs(retrained);
}

export const improvementStrip = differenceStrip({
    key: "improvement",
    label: "Improvement over the model in use",
    colourKey: "r",
    value: improvement,
    markZero: true,
});
