import { formatNumber } from "../format";
import { COMPARISON_COLUMN } from "../rows";
import { state } from "../state";
import { colourFor } from "../theme";

const UNIT = "°C";
const DECIMALS = 2;

/** Reference minus a model's estimate, °C. */
export function difference(
    row,
    column = COMPARISON_COLUMN.estimated,
    referenceColumn = COMPARISON_COLUMN.shade,
) {
    const estimated = row[column] ?? null;
    const reference = row[referenceColumn] ?? null;

    return estimated === null || reference === null ? null : reference - estimated;
}

export function formatDifference(value) {
    if (value === null) {
        return "n/a";
    }

    const sign = value > 0 ? "+" : value < 0 ? "−" : "";

    return `${sign}${formatNumber(Math.abs(value), DECIMALS)} ${UNIT}`;
}

/** °C lines over the comparison rows; each line is { label, colourKey, value, dashed }. */
export function differenceStrip({ key, lines, markZero = false }) {
    return {
        key,
        zoom: false,
        time: COMPARISON_COLUMN.time,
        rows: () => state.comparisonRows,
        lines: (row) =>
            lines.map((line) => ({
                label: line.label,
                value: formatDifference(line.value(row)),
                colour: colourFor(line.colourKey),
            })),
        option: (strip, colours) => ({
            yAxis: {
                type: "value",
                axisLabel: { color: colours.label, fontSize: 10 },
                splitLine: { lineStyle: { color: colours.grid } },
            },
            series: lines.map((line, index) => ({
                name: line.label,
                type: "line",
                showSymbol: false,
                connectNulls: false,
                z: lines.length - index,
                lineStyle: {
                    width: line.dashed ? 1 : 1.5,
                    type: line.dashed ? "dashed" : "solid",
                    color: colourFor(line.colourKey),
                },
                itemStyle: { color: colourFor(line.colourKey) },
                ...(markZero &&
                    index === 0 && {
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
                    line.value(row),
                ]),
            })),
        }),
    };
}
