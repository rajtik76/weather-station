import { difference, differenceStrip } from "./difference";

export const comparisonStrip = differenceStrip({
    key: "comparison",
    label: "Mikulka − shade estimate",
    colourKey: "m",
    value: (row) => difference(row),
});
