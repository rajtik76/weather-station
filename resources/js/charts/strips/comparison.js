import { formatNumber } from "../format";
import { COMPARISON_COLUMN } from "../rows";
import { state } from "../state";
import { colourFor } from "../theme";

const DIFFERENCE = { key: "m", label: "Mikulka − shade estimate", unit: "°C", decimals: 2 };

export function difference(row) {
    const estimated = row[COMPARISON_COLUMN.estimated];
    const reference = row[COMPARISON_COLUMN.reference];

    return estimated === null || reference === null ? null : reference - estimated;
}

function formatDifference(value) {
    if (value === null) {
        return "n/a";
    }

    const sign = value > 0 ? "+" : value < 0 ? "−" : "";

    return `${sign}${formatNumber(Math.abs(value), DIFFERENCE.decimals)} ${DIFFERENCE.unit}`;
}

export const comparisonStrip = {
    key: "comparison",
    zoom: false,
    time: COMPARISON_COLUMN.time,
    rows: () => state.comparisonRows,
    lines: (row) => [
        {
            label: DIFFERENCE.label,
            value: formatDifference(difference(row)),
            colour: colourFor(DIFFERENCE.key),
        },
    ],
    option: (strip, colours) => ({
        yAxis: {
            type: "value",
            axisLabel: { color: colours.label, fontSize: 10 },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: [
            {
                name: DIFFERENCE.label,
                type: "line",
                showSymbol: false,
                connectNulls: false,
                lineStyle: { width: 1.5, color: colourFor(DIFFERENCE.key) },
                itemStyle: { color: colourFor(DIFFERENCE.key) },
                data: state.comparisonRows.map((row) => [
                    row[COMPARISON_COLUMN.time],
                    difference(row),
                ]),
            },
        ],
    }),
};
