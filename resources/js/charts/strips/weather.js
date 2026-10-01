import { formatNumber, formatValue } from "../format";
import { COLUMN } from "../rows";
import { state } from "../state";
import { BAND_OPACITY, colourFor, mixColours } from "../theme";

/**
 * `axis`: the channel whose value axis this one shares (dew point reads the
 * temperature's). `band`: row columns with the min and max sample behind the
 * mean; the dew point is derived and has none.
 */
const CHANNELS = [
    {
        key: "t",
        label: "Temperature",
        unit: "°C",
        decimals: 2,
        axis: "t",
        band: ["tMin", "tMax"],
    },
    {
        key: "h",
        label: "Humidity",
        unit: "%",
        decimals: 2,
        axis: "h",
        band: ["hMin", "hMax"],
    },
    {
        key: "d",
        label: "Dew point",
        unit: "°C",
        decimals: 2,
        axis: "t",
        dashed: true,
    },
    {
        key: "p",
        label: "Pressure, MSL",
        unit: "hPa",
        decimals: 2,
        axis: "p",
        band: ["pMin", "pMax"],
    },
];

const channelFor = (key) => CHANNELS.find((candidate) => candidate.key === key);

const isShown = (channel) => !state.hidden.has(channel.key);

/**
 * One line: its colour. Two lines: a gradient from the first's colour at the
 * top to the second's at the bottom, spread over the data range because
 * ECharts hands a label its value, not its position.
 */
function axisLabelStyle(axis, channels) {
    const readers = channels.filter((entry) => entry.axis === axis.key);

    if (readers.length < 2) {
        return { color: colourFor(readers[0]?.key ?? axis.key) };
    }

    const values = readers.flatMap((entry) =>
        state.rows.map((row) => row[COLUMN[entry.key]]).filter((value) => value !== null),
    );
    const low = Math.min(...values);
    const high = Math.max(...values);
    const top = colourFor(readers[0].key);
    const bottom = colourFor(readers[readers.length - 1].key);

    if (values.length === 0 || high === low) {
        return { color: top };
    }

    return {
        color: (value) => {
            const ratio = Math.min(1, Math.max(0, (value - low) / (high - low)));

            return mixColours(bottom, top, ratio);
        },
    };
}

/** Only the strip's own channels, and of those only the ones switched on. */
function weatherLines(row, strip) {
    return strip.channels
        .map(channelFor)
        .filter(isShown)
        .map((channel) => ({
            label: channel.label,
            value: formatValue(row[COLUMN[channel.key]], channel),
            colour: colourFor(channel.key),
            detail: spreadText(row, channel),
        }));
}

/** Min and max under the mean, only where they differ (V1 rows have no spread). */
function spreadText(row, channel) {
    const [low, high] = spreadOf(row, channel);

    if (low === null || high === null || low === high) {
        return "";
    }

    return `${formatNumber(low, channel.decimals)} to ${formatNumber(high, channel.decimals)} ${channel.unit}`;
}

function spreadOf(row, channel) {
    if (!channel.band) {
        return [null, null];
    }

    return channel.band.map((column) => row[COLUMN[column]] ?? null);
}

function weatherOption(strip, colours) {
    const channels = strip.channels.map(channelFor).filter(isShown);
    // One axis per distinct `axis` among the drawn lines, in declared order,
    // so the temperature axis keeps the left whichever of its lines is on.
    const wanted = new Set(channels.map((entry) => entry.axis));
    const axes = [...new Set(strip.channels.map((key) => channelFor(key).axis))]
        .filter((key) => wanted.has(key))
        .map(channelFor);

    return {
        yAxis: axes.map((entry, index) => ({
            type: "value",
            scale: true,
            position: index === 0 ? "left" : "right",
            axisLabel: { fontSize: 10, ...axisLabelStyle(entry, channels) },
            // Grid lines from the first axis only.
            splitLine: {
                show: index === 0,
                lineStyle: { color: colours.grid },
            },
        })),
        // Bands first, lines over them.
        series: [
            ...channels.flatMap((entry) =>
                bandSeries(
                    entry,
                    axes.findIndex((axis) => axis.key === entry.axis),
                ),
            ),
            ...channels.map((entry) => ({
                type: "line",
                name: entry.label,
                yAxisIndex: axes.findIndex((axis) => axis.key === entry.axis),
                showSymbol: false,
                // Derived line, dashed.
                lineStyle: {
                    width: 1.5,
                    color: colourFor(entry.key),
                    type: entry.dashed ? [6, 4] : "solid",
                },
                itemStyle: { color: colourFor(entry.key) },
                data: state.rows.map((row) => [row[COLUMN.time], row[COLUMN[entry.key]]]),
            })),
        ],
    };
}

/**
 * ECharts has no band series: an invisible line along the minimum and the
 * spread stacked on it with its area filled. Both silent; the tooltip reads
 * the extremes off the row.
 */
function bandSeries(channel, yAxisIndex) {
    if (!channel.band) {
        return [];
    }

    const [lowColumn, highColumn] = channel.band.map((column) => COLUMN[column]);
    const spread = (row) =>
        row[lowColumn] === null || row[highColumn] === null
            ? null
            : row[highColumn] - row[lowColumn];
    const shared = {
        type: "line",
        stack: `band-${channel.key}`,
        // Default stacking only stacks one sign; a winter minimum is negative, the spread is not.
        stackStrategy: "all",
        yAxisIndex,
        silent: true,
        showSymbol: false,
        lineStyle: { width: 0 },
        emphasis: { disabled: true },
        tooltip: { show: false },
    };

    return [
        {
            ...shared,
            name: `${channel.label} minimum`,
            data: state.rows.map((row) => [row[COLUMN.time], row[lowColumn]]),
        },
        {
            ...shared,
            name: `${channel.label} maximum`,
            areaStyle: { color: colourFor(channel.key), opacity: BAND_OPACITY },
            data: state.rows.map((row) => [row[COLUMN.time], spread(row)]),
        },
    ];
}

/** The temperature strip: temperature, humidity and the derived dew point. Carries the event icons. */
export const temperatureStrip = {
    key: "th",
    events: true,
    channels: ["t", "h", "d"],
    option: weatherOption,
    lines: weatherLines,
    rows: () => state.rows,
    time: COLUMN.time,
};

/** The pressure strip; on the temperature strip's axis its 40 hPa range would be a flat line. */
export const pressureStrip = {
    key: "p",
    channels: ["p"],
    option: weatherOption,
    lines: weatherLines,
    rows: () => state.rows,
    time: COLUMN.time,
};
