import { formatNumber } from "../format";
import { COMPARISON_COLUMN } from "../rows";
import { state } from "../state";
import { colourFor } from "../theme";

const UNIT = "°C";
const DECIMALS = 2;

export function difference(row, column = COMPARISON_COLUMN.estimated) {
    const estimated = row[column] ?? null;
    const reference = row[COMPARISON_COLUMN.reference];

    return estimated === null || reference === null ? null : reference - estimated;
}

export function formatDifference(value) {
    if (value === null) {
        return "n/a";
    }

    const sign = value > 0 ? "+" : value < 0 ? "−" : "";

    return `${sign}${formatNumber(Math.abs(value), DECIMALS)} ${UNIT}`;
}

/** One °C line over the comparison rows. */
export function differenceStrip({ key, label, colourKey, value, markZero = false }) {
    return {
        key,
        zoom: false,
        time: COMPARISON_COLUMN.time,
        rows: () => state.comparisonRows,
        lines: (row) => [
            { label, value: formatDifference(value(row)), colour: colourFor(colourKey) },
        ],
        option: (strip, colours) => ({
            yAxis: {
                type: "value",
                axisLabel: { color: colours.label, fontSize: 10 },
                splitLine: { lineStyle: { color: colours.grid } },
            },
            series: [
                {
                    name: label,
                    type: "line",
                    showSymbol: false,
                    connectNulls: false,
                    lineStyle: { width: 1.5, color: colourFor(colourKey) },
                    itemStyle: { color: colourFor(colourKey) },
                    ...(markZero && {
                        markLine: {
                            silent: true,
                            symbol: "none",
                            label: { show: false },
                            lineStyle: { color: colours.label, type: "solid", width: 1 },
                            data: [{ yAxis: 0 }],
                        },
                    }),
                    data: state.comparisonRows.map((row) => [
                        row[COMPARISON_COLUMN.time],
                        value(row),
                    ]),
                },
            ],
        }),
    };
}
