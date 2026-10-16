import { formatNumber } from "../format";
import { COMPARISON_COLUMN } from "../rows";
import { state } from "../state";
import { colourFor } from "../theme";

const DIFFERENCES = [
    { key: "m", column: COMPARISON_COLUMN.estimated, label: "Mikulka − shade estimate" },
    { key: "r", column: COMPARISON_COLUMN.candidate, label: "Mikulka − retrained model" },
];
const UNIT = "°C";
const DECIMALS = 2;

export function difference(row, column = COMPARISON_COLUMN.estimated) {
    const estimated = row[column] ?? null;
    const reference = row[COMPARISON_COLUMN.reference];

    return estimated === null || reference === null ? null : reference - estimated;
}

function formatDifference(value) {
    if (value === null) {
        return "n/a";
    }

    const sign = value > 0 ? "+" : value < 0 ? "−" : "";

    return `${sign}${formatNumber(Math.abs(value), DECIMALS)} ${UNIT}`;
}

function shownDifferences() {
    return DIFFERENCES.filter(
        ({ column }) =>
            column === COMPARISON_COLUMN.estimated ||
            state.comparisonRows.some((row) => (row[column] ?? null) !== null),
    );
}

export const comparisonStrip = {
    key: "comparison",
    zoom: false,
    time: COMPARISON_COLUMN.time,
    rows: () => state.comparisonRows,
    lines: (row) =>
        shownDifferences().map(({ key, column, label }) => ({
            label,
            value: formatDifference(difference(row, column)),
            colour: colourFor(key),
        })),
    option: (strip, colours) => ({
        yAxis: {
            type: "value",
            axisLabel: { color: colours.label, fontSize: 10 },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: shownDifferences().map(({ key, column, label }) => ({
            name: label,
            type: "line",
            showSymbol: false,
            connectNulls: false,
            lineStyle: { width: 1.5, color: colourFor(key) },
            itemStyle: { color: colourFor(key) },
            data: state.comparisonRows.map((row) => [
                row[COMPARISON_COLUMN.time],
                difference(row, column),
            ]),
        })),
    }),
};
