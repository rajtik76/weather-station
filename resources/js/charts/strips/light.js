import { formatLux, formatNumber } from "../format";
import { LIGHT_COLUMN } from "../rows";
import { state } from "../state";
import { BAND_OPACITY, colourFor } from "../theme";

/**
 * A log axis has no zero, and a dark night reads 0 lx. Anything below this
 * is drawn on it; the tooltip still prints the value as measured.
 */
const LIGHT_FLOOR = 0.01;

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

function floorLux(value) {
    return value === null ? null : Math.max(value, LIGHT_FLOOR);
}

/**
 * The mean over the min-max band on a log axis: dusk and noon are four
 * orders apart, and on a linear axis every night would be one flat line.
 */
function lightOption(strip, colours) {
    const colour = lightColour();
    const time = (row) => row[LIGHT_COLUMN.time];
    const spread = (row) =>
        row[LIGHT_COLUMN.min] === null || row[LIGHT_COLUMN.max] === null
            ? null
            : floorLux(row[LIGHT_COLUMN.max]) - floorLux(row[LIGHT_COLUMN.min]);

    return {
        yAxis: {
            type: "log",
            min: LIGHT_FLOOR,
            axisLabel: {
                fontSize: 10,
                color: colour,
                formatter: (value) => formatNumber(value, value < 1 ? 2 : 0),
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
                data: state.lightRows.map((row) => [time(row), floorLux(row[LIGHT_COLUMN.min])]),
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
                data: state.lightRows.map((row) => [time(row), floorLux(row[LIGHT_COLUMN.mean])]),
            },
        ],
    };
}

/** The light strip: the mean over its min-max band on a log axis. */
export const lightStrip = {
    key: "light",
    option: lightOption,
    lines: lightLines,
    rows: () => state.lightRows,
    time: LIGHT_COLUMN.time,
};
