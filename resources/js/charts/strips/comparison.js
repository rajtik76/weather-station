import { COMPARISON_COLUMN } from "../rows";
import { difference, differenceStrip } from "./difference";

export const comparisonStrip = differenceStrip({
    key: "comparison",
    lines: [
        {
            label: "Shade reference − shade estimate",
            colourKey: "m",
            value: (row) => difference(row),
        },
        {
            label: "Mikulka − shade estimate",
            colourKey: "c",
            dashed: true,
            value: (row) => difference(row, COMPARISON_COLUMN.estimated, COMPARISON_COLUMN.mikulka),
        },
    ],
});
