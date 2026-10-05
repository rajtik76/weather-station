import { formatLux, formatNumber } from "../format";
import { lightAxis, plottedLux } from "../light-scale";
import { LIGHT_COLUMN } from "../rows";
import { state } from "../state";
import { BAND_OPACITY, colourFor } from "../theme";

function lightColour() {
    return colourFor("light");
}

function lightLines(row) {
    if (row[LIGHT_COLUMN.mean] === null) {
        return [{ label: "Light", value: "n/a", strong: true }];
    }

    return [
        {
            label: "Light",
            value: formatLux(row[LIGHT_COLUMN.mean]),
            colour: lightColour(),
            strong: true,
            detail: `${formatLux(row[LIGHT_COLUMN.min])} to ${formatLux(row[LIGHT_COLUMN.max])}`,
        },
    ];
}

/** Tooltip prints the measured lux, the line the plotted one. */
function lightOption(strip, colours) {
    const colour = lightColour();
    const plot = (value) => plottedLux(value, state.lightScale);
    const time = (row) => row[LIGHT_COLUMN.time];
    const spread = (row) =>
        row[LIGHT_COLUMN.min] === null || row[LIGHT_COLUMN.max] === null
            ? null
            : plot(row[LIGHT_COLUMN.max]) - plot(row[LIGHT_COLUMN.min]);

    return {
        yAxis: {
            ...lightAxis(state.lightScale),
            axisLabel: {
                fontSize: 10,
                color: colour,
                formatter: (value) => formatNumber(value, value > 0 && value < 1 ? 2 : 0),
            },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: [
            {
                type: "line",
                stack: "light-band",
                stackStrategy: "all",
                silent: true,
                showSymbol: false,
                lineStyle: { opacity: 0 },
                data: state.lightRows.map((row) => [time(row), plot(row[LIGHT_COLUMN.min])]),
            },
            {
                type: "line",
                stack: "light-band",
                stackStrategy: "all",
                silent: true,
                showSymbol: false,
                lineStyle: { opacity: 0 },
                areaStyle: { color: colour, opacity: BAND_OPACITY },
                data: state.lightRows.map((row) => [time(row), spread(row)]),
            },
            {
                type: "line",
                name: "Light",
                showSymbol: false,
                lineStyle: { width: 1.5, color: colour },
                itemStyle: { color: colour },
                data: state.lightRows.map((row) => [time(row), plot(row[LIGHT_COLUMN.mean])]),
            },
        ],
    };
}

export const lightStrip = {
    key: "light",
    option: lightOption,
    lines: lightLines,
    rows: () => state.lightRows,
    time: LIGHT_COLUMN.time,
};
