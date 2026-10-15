import { formatValue } from "../format";
import { COMPARISON_COLUMN } from "../rows";
import { state } from "../state";
import { colourFor } from "../theme";

const CHANNELS = [
    { key: "t", column: "measured", label: "Shield sensor", unit: "°C", decimals: 2 },
    { key: "c", column: "estimated", label: "Shade estimate", unit: "°C", decimals: 2 },
    { key: "m", column: "reference", label: "ČHMÚ Mikulka", unit: "°C", decimals: 2 },
];

export const comparisonStrip = {
    key: "comparison",
    zoom: false,
    time: COMPARISON_COLUMN.time,
    rows: () => state.comparisonRows,
    lines: (row) =>
        CHANNELS.map((channel) => ({
            label: channel.label,
            value: formatValue(row[COMPARISON_COLUMN[channel.column]], channel),
            colour: colourFor(channel.key),
        })),
    option: (strip, colours) => ({
        yAxis: {
            type: "value",
            scale: true,
            axisLabel: { color: colours.label, fontSize: 10 },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: CHANNELS.map((channel) => ({
            name: channel.label,
            type: "line",
            showSymbol: false,
            connectNulls: false,
            lineStyle: {
                width: 1.5,
                color: colourFor(channel.key),
                type: channel.key === "c" ? [6, 4] : "solid",
            },
            itemStyle: { color: colourFor(channel.key) },
            data: state.comparisonRows.map((row) => [
                row[COMPARISON_COLUMN.time],
                row[COMPARISON_COLUMN[channel.column]],
            ]),
        })),
    }),
};
