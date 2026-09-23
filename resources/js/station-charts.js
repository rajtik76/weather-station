// Piecemeal imports: the full ECharts bundle is ~400 kB gzipped.
import * as echarts from "echarts/core";
import { CustomChart, LineChart } from "echarts/charts";
import {
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    MarkLineComponent,
    TooltipComponent,
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";

echarts.use([
    CustomChart,
    LineChart,
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    MarkLineComponent,
    TooltipComponent,
    CanvasRenderer,
]);

/**
 * One ECharts instance per strip, connected so crosshair and zoom track
 * together. A zoom is a server round trip: the selected epochs go to
 * Livewire, which re-queries at the bucket width the new span needs.
 */

const GROUP = "station";

const DRAG_SLOP_PX = 6;

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

/** Hidden channels, as the server's payload states them so the choice survives a poll. */
let hidden = new Set();

const isShown = (channel) => !hidden.has(channel.key);

/** Pressure has its own strip: on a shared axis its 40 hPa range is a flat line. */
const STRIPS = [
    { key: "th", channels: ["t", "h", "d"], option: optionFor },
    { key: "p", channels: ["p"], option: optionFor },
    { key: "noise", option: noiseOption },
    { key: "spectrum", option: spectrumOption },
];

/** Tick labels per unit. ECharts' default prints a bare day number; all numeric to stay language-neutral. */
const TIME_LABELS = {
    year: "{yyyy}",
    month: "{M}/{yyyy}",
    day: "{d}. {M}.",
    hour: "{HH}:{mm}",
    minute: "{HH}:{mm}",
    second: "{HH}:{mm}:{ss}",
    millisecond: "{HH}:{mm}:{ss}",
    none: "{d}. {M}. {yyyy}",
};

/**
 * Row layout from the server: wall-clock ms, °C, %, hPa, dew point, epoch,
 * then a min-max pair per channel on strip rows only. A missed slot is nulls.
 */
const COLUMN = {
    time: 0,
    t: 1,
    h: 2,
    p: 3,
    d: 4,
    epoch: 5,
    tMin: 6,
    tMax: 7,
    hMin: 8,
    hMax: 9,
    pMin: 10,
    pMax: 11,
};

const BAND_OPACITY = 0.16;

/**
 * Noise row from the server: wall-clock ms, epoch, LAeq, LA10, LA90, LAmax,
 * then the 26 third-octave bands, all dB. A slot without noise is nulls.
 */
const NOISE_COLUMN = { time: 0, epoch: 1, laeq: 2, la10: 3, la90: 4, lamax: 5, band: 6 };

/** Nominal third-octave centres, Hz. */
const NOISE_BANDS = [
    "25",
    "31.5",
    "40",
    "50",
    "63",
    "80",
    "100",
    "125",
    "160",
    "200",
    "250",
    "315",
    "400",
    "500",
    "630",
    "800",
    "1k",
    "1.25k",
    "1.6k",
    "2k",
    "2.5k",
    "3.15k",
    "4k",
    "5k",
    "6.3k",
    "8k",
];

/** Emerald: its own strip, and a hue none of the weather lines use. */
const NOISE_COLOUR = { light: "#059669", dark: "#34d399" };

/**
 * One-hue sequential ramp for the spectrum, light to dark: loud is dark on
 * the light ground. On the dark ground the order flips, so quiet sinks into
 * the surface there too.
 */
const SPECTRUM_RAMP = [
    "#cde2fb",
    "#b7d3f6",
    "#9ec5f4",
    "#86b6ef",
    "#6da7ec",
    "#5598e7",
    "#3987e5",
    "#2a78d6",
    "#256abf",
    "#1c5cab",
    "#184f95",
    "#104281",
    "#0d366b",
];

/** Event row: wall-clock ms, title, CSS colour or null. */
const EVENT = { time: 0, title: 1, colour: 2 };

/** Fallback event colour; a mid-grey that works on both grounds. */
const EVENT_COLOUR = "#a1a1aa";

/** Shared by every canvas so the stacked time axes line up pixel for pixel; the right margin is a second value axis's width. */
const GRID_SIDES = { left: 64, right: 64 };

const charts = new Map();

let rows = [];

let events = [];

let noiseRows = [];

function isDark() {
    return document.documentElement.classList.contains("dark");
}

/** Two palettes, not CSS variables: ECharts paints onto a canvas. */
function palette() {
    return isDark()
        ? {
              axis: "#52525b",
              label: "#a1a1aa",
              grid: "#ffffff14",
              surface: "#27272a",
              border: "#3f3f46",
              text: "#e4e4e7",
          }
        : {
              axis: "#d4d4d8",
              label: "#a1a1aa",
              grid: "#0000000d",
              surface: "#ffffff",
              border: "#e4e4e7",
              text: "#27272a",
          };
}

const LINE_COLOUR = {
    t: { light: "#d97706", dark: "#f59e0b" },
    h: { light: "#0891b2", dark: "#22d3ee" },
    // Pink: green read as a shade of the amber on the light ground.
    d: { light: "#db2777", dark: "#f472b6" },
    p: { light: "#7c3aed", dark: "#a78bfa" },
};

function colourFor(key) {
    return LINE_COLOUR[key][isDark() ? "dark" : "light"];
}

function mixColours(from, to, ratio) {
    const channel = (hex, offset) => parseInt(hex.slice(offset, offset + 2), 16);
    const blend = (offset) =>
        Math.round(channel(from, offset) + (channel(to, offset) - channel(from, offset)) * ratio)
            .toString(16)
            .padStart(2, "0");

    return `#${blend(1)}${blend(3)}${blend(5)}`;
}

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
        rows.map((row) => row[COLUMN[entry.key]]).filter((value) => value !== null),
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

const numberFormats = new Map();

function formatNumber(value, decimals) {
    if (!numberFormats.has(decimals)) {
        numberFormats.set(
            decimals,
            new Intl.NumberFormat("cs-CZ", {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }),
        );
    }

    return numberFormats.get(decimals).format(value);
}

function formatValue(value, channel) {
    return value === null ? "n/a" : `${formatNumber(value, channel.decimals)} ${channel.unit}`;
}

/** Stamps are wall-clock ms tagged UTC (see Dashboard::wallClockMs), so formatters read them as UTC. */
const stampFormat = new Intl.DateTimeFormat("cs-CZ", {
    day: "numeric",
    month: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "UTC",
});

function nearestRow(list, time, column = COLUMN.time) {
    if (list.length === 0) {
        return null;
    }

    let low = 0;
    let high = list.length - 1;

    while (low < high) {
        const mid = (low + high) >> 1;

        if (list[mid][column] < time) {
            low = mid + 1;
        } else {
            high = mid;
        }
    }

    const after = list[low];
    const before = list[Math.max(0, low - 1)];

    return Math.abs(before[column] - time) <= Math.abs(after[column] - time) ? before : after;
}

function rowAt(time) {
    return nearestRow(rows, time);
}

/** Wall-clock ms back to a real epoch. The offset changes with DST, so it is read off the nearest row, which carries both. */
function epochFromWallMs(list, milliseconds) {
    const row = nearestRow(list, milliseconds);

    if (!row) {
        return null;
    }

    const offsetSeconds = Math.round(row[COLUMN.time] / 1000) - row[COLUMN.epoch];

    return Math.round(milliseconds / 1000) - offsetSeconds;
}

function tooltipHtml(params) {
    if (hovered) {
        return eventTooltipHtml(hovered);
    }

    const point = Array.isArray(params) ? params[0] : params;
    const row = rowAt(point?.axisValue);

    if (!row) {
        return "";
    }

    return readingsHtml(row);
}

function readingsHtml(row) {
    const colours = palette();
    const heading =
        `<div style="font-weight:500;margin-bottom:4px;color:${colours.text}">` +
        `${stampFormat.format(new Date(row[COLUMN.time]))}</div>`;

    const lines = CHANNELS.filter(isShown)
        .map((channel) => {
            const dot =
                `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;` +
                `background:${colourFor(channel.key)};margin-right:6px"></span>`;

            return (
                `<div style="display:flex;align-items:center;gap:12px">` +
                `<span>${dot}${channel.label}</span>` +
                `<span style="margin-left:auto;font-variant-numeric:tabular-nums;color:${colours.text}">` +
                `${formatValue(row[COLUMN[channel.key]], channel)}</span>` +
                `</div>` +
                spreadHtml(row, channel)
            );
        })
        .join("");

    return heading + lines;
}

/** Min and max under the mean, only where they differ (V1 rows and navigator rows have no spread). */
function spreadHtml(row, channel) {
    const [low, high] = spreadOf(row, channel);

    if (low === null || high === null || low === high) {
        return "";
    }

    return (
        `<div style="display:flex;gap:12px;font-size:11px;opacity:0.8">` +
        `<span style="margin-left:auto;font-variant-numeric:tabular-nums">` +
        `${formatNumber(low, channel.decimals)} to ${formatNumber(high, channel.decimals)} ${channel.unit}</span>` +
        `</div>`
    );
}

function spreadOf(row, channel) {
    if (!channel.band) {
        return [null, null];
    }

    return channel.band.map((column) => row[COLUMN[column]] ?? null);
}

function labelsEvents(strip) {
    return strip === STRIPS[0] && events.length > 0;
}

const EVENT_LABEL_ROOM = 30;

const eventStampFormat = new Intl.DateTimeFormat("cs-CZ", {
    day: "numeric",
    month: "numeric",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "UTC",
});

/** Titles are hand-typed and land in innerHTML. */
function escapeHtml(text) {
    const node = document.createElement("span");
    node.textContent = text;

    return node.innerHTML;
}

/** Median spacing between rows, to tell a gap from a step. Read off the rows because the bucket width varies with the window. */
let step = 0;

function typicalStep(list, column = COLUMN.time) {
    if (list.length < 2) {
        return 0;
    }

    const gaps = [];

    for (let index = 1; index < list.length; index++) {
        gaps.push(list[index][column] - list[index - 1][column]);
    }

    gaps.sort((left, right) => left - right);

    return gaps[gaps.length >> 1];
}

function hasReadingAt(time) {
    const row = rowAt(time);

    return row !== null && Math.abs(row[COLUMN.time] - time) <= step * 1.5 ? row : null;
}

/**
 * The event line under the pointer. Event lines have no tooltip of their
 * own: an item tooltip on a connected chart broadcasts its data index and the
 * other strip jumps to that reading. The shared axis tooltip reads this instead.
 */
let hovered = null;

function trackEventHover(chart) {
    // mousemove, not mouseover: zrender fires mouseover after the axis
    // pointer's global handler, so the first tooltip would miss the title.
    chart.on("mousemove", (params) => {
        if (params.componentType === "markLine") {
            hovered = {
                name: params.name,
                time: params.data.xAxis,
                colour: params.data.lineStyle?.color ?? EVENT_COLOUR,
            };
        }
    });

    chart.on("mouseout", (params) => {
        if (params.componentType === "markLine") {
            hovered = null;
        }
    });
}

/** Over readings: their tooltip with the title above. Over a hole: the event alone, with its date. */
function eventTooltipHtml(event) {
    const colours = palette();
    const row = hasReadingAt(event.time);

    const title =
        `<div style="font-weight:600;font-size:14px;color:${colours.text}">` +
        `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;` +
        `background:${event.colour};margin-right:6px"></span>${escapeHtml(event.name)}</div>`;

    if (row) {
        return (
            title +
            `<div style="margin-top:6px;padding-top:6px;border-top:1px solid ${colours.border}">` +
            `${readingsHtml(row)}</div>`
        );
    }

    return (
        title + `<div style="margin-top:2px">${eventStampFormat.format(new Date(event.time))}</div>`
    );
}

/**
 * Event lines on every strip, title on the top one only. Label at `end`:
 * every `inside*` position lays the text along the line, sideways.
 */
function eventMarks(strip) {
    return {
        animation: false,
        symbol: "none",
        emphasis: { disabled: true },
        tooltip: { show: false },
        label: {
            show: labelsEvents(strip),
            position: "end",
            distance: 4,
            fontSize: 12,
            formatter: (mark) => mark.name,
        },
        data: eventLines(),
    };
}

/** The column takes any string and the value lands in a canvas style and in markup; only a real colour gets through. */
function eventColour(value) {
    return typeof value === "string" && CSS.supports("color", value) ? value : EVENT_COLOUR;
}

function eventLines() {
    return events.map((event) => {
        const colour = eventColour(event[EVENT.colour]);

        return {
            name: event[EVENT.title],
            xAxis: event[EVENT.time],
            // Explicit pattern: "dashed" scales with the width and at 2 px the gaps outgrew the dashes.
            lineStyle: { color: colour, type: [4, 3], width: 2, opacity: 0.9 },
            label: { color: colour },
        };
    });
}

function optionFor(strip) {
    const colours = palette();
    const channels = strip.channels.map(channelFor).filter(isShown);
    // One axis per distinct `axis` among the drawn lines, in declared order,
    // so the temperature axis keeps the left whichever of its lines is on.
    const wanted = new Set(channels.map((entry) => entry.axis));
    const axes = [...new Set(strip.channels.map((key) => channelFor(key).axis))]
        .filter((key) => wanted.has(key))
        .map(channelFor);

    return {
        animation: false,
        // Stamps already carry the local offset; UTC keeps the axis on station time for every viewer.
        useUTC: true,
        grid: {
            ...GRID_SIDES,
            top: labelsEvents(strip) ? EVENT_LABEL_ROOM : 12,
            bottom: 28,
        },
        tooltip: tooltipFrame(colours, tooltipHtml),
        axisPointer: { snap: true },
        xAxis: timeAxis(colours),
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
            ...channels.map((entry, index) => ({
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
                data: rows.map((row) => [row[COLUMN.time], row[COLUMN[entry.key]]]),
                markLine: index === 0 ? eventMarks(strip) : undefined,
            })),
        ],
    };
}

function noiseColour() {
    return NOISE_COLOUR[isDark() ? "dark" : "light"];
}

/** Every strip's time axis, so the stacked canvases line up. */
function timeAxis(colours) {
    return {
        type: "time",
        axisLine: { lineStyle: { color: colours.axis } },
        axisLabel: {
            color: colours.label,
            fontSize: 10,
            hideOverlap: true,
            formatter: TIME_LABELS,
        },
        splitLine: { show: true, lineStyle: { color: colours.grid } },
    };
}

/**
 * Always the axis trigger: an item tooltip on a connected chart broadcasts
 * its data index and the other strips jump to that reading (see hovered).
 */
function tooltipFrame(colours, formatter) {
    return {
        trigger: "axis",
        appendToBody: true,
        backgroundColor: colours.surface,
        borderColor: colours.border,
        textStyle: { color: colours.label, fontSize: 12 },
        formatter,
    };
}

function noiseRowAt(time) {
    return nearestRow(noiseRows, time, NOISE_COLUMN.time);
}

function noiseLine(label, value, colours, strong = false) {
    return (
        `<div style="display:flex;align-items:center;gap:12px">` +
        `<span>${label}</span>` +
        `<span style="margin-left:auto;font-variant-numeric:tabular-nums;color:${colours.text};` +
        `${strong ? "font-weight:500" : ""}">` +
        `${value === null ? "n/a" : `${formatNumber(value, 1)} dB(A)`}</span></div>`
    );
}

function noiseTooltipHtml(params) {
    const point = Array.isArray(params) ? params[0] : params;
    const row = noiseRowAt(point?.axisValue);

    if (!row) {
        return "";
    }

    const colours = palette();

    return (
        `<div style="font-weight:500;margin-bottom:4px;color:${colours.text}">` +
        `${stampFormat.format(new Date(row[NOISE_COLUMN.time]))}</div>` +
        noiseLine("LAeq", row[NOISE_COLUMN.laeq], colours, true) +
        noiseLine("LA10", row[NOISE_COLUMN.la10], colours) +
        noiseLine("LA90", row[NOISE_COLUMN.la90], colours) +
        noiseLine("LAmax", row[NOISE_COLUMN.lamax], colours)
    );
}

/** LAeq over the LA90 to LA10 band - the level most of the window sat in - with LAmax dotted above. */
function noiseOption() {
    const colours = palette();
    const colour = noiseColour();
    const time = (row) => row[NOISE_COLUMN.time];
    const spread = (row) =>
        row[NOISE_COLUMN.la90] === null || row[NOISE_COLUMN.la10] === null
            ? null
            : row[NOISE_COLUMN.la10] - row[NOISE_COLUMN.la90];

    return {
        animation: false,
        useUTC: true,
        grid: { ...GRID_SIDES, top: 12, bottom: 28 },
        tooltip: tooltipFrame(colours, noiseTooltipHtml),
        axisPointer: { snap: true },
        xAxis: timeAxis(colours),
        yAxis: {
            type: "value",
            scale: true,
            axisLabel: { fontSize: 10, color: colour },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: [
            {
                type: "line",
                stack: "noise-band",
                stackStrategy: "all",
                silent: true,
                showSymbol: false,
                lineStyle: { opacity: 0 },
                data: noiseRows.map((row) => [time(row), row[NOISE_COLUMN.la90]]),
            },
            {
                type: "line",
                stack: "noise-band",
                stackStrategy: "all",
                silent: true,
                showSymbol: false,
                lineStyle: { opacity: 0 },
                areaStyle: { color: colour, opacity: BAND_OPACITY },
                data: noiseRows.map((row) => [time(row), spread(row)]),
            },
            {
                type: "line",
                name: "LAmax",
                showSymbol: false,
                lineStyle: { width: 1, color: colour, opacity: 0.6, type: [2, 3] },
                itemStyle: { color: colour },
                data: noiseRows.map((row) => [time(row), row[NOISE_COLUMN.lamax]]),
            },
            {
                type: "line",
                name: "LAeq",
                showSymbol: false,
                lineStyle: { width: 1.5, color: colour },
                itemStyle: { color: colour },
                data: noiseRows.map((row) => [time(row), row[NOISE_COLUMN.laeq]]),
            },
        ],
    };
}

/** The window's quietest and loudest band, so the ramp spends its steps on what is there. */
function spectrumRange() {
    const values = noiseRows.flatMap((row) =>
        row.slice(NOISE_COLUMN.band).filter((value) => value !== null),
    );

    if (values.length === 0) {
        return null;
    }

    const low = Math.min(...values);
    const high = Math.max(...values);

    return { low, high: high === low ? low + 1 : high };
}

function spectrumRamp() {
    return isDark() ? [...SPECTRUM_RAMP].reverse() : SPECTRUM_RAMP;
}

function spectrumColour(value, range) {
    const ramp = spectrumRamp();
    const position =
        Math.min(1, Math.max(0, (value - range.low) / (range.high - range.low))) *
        (ramp.length - 1);
    const index = Math.min(ramp.length - 2, Math.floor(position));

    return mixColours(ramp[index], ramp[index + 1], position - index);
}

/** The pointer over the waterfall, null off it; the axis tooltip knows the time, not the band. */
let spectrumPointer = null;

function trackSpectrumPointer(chart) {
    chart.getZr().on("mousemove", (event) => {
        spectrumPointer = { x: event.offsetX, y: event.offsetY };
    });
    chart.getZr().on("globalout", () => {
        spectrumPointer = null;
    });
}

/** The band under the pointer, or the loudest band of the slot when the pointer is on another strip. */
function spectrumBandAt(row) {
    const chart = charts.get("spectrum");
    const levels = row.slice(NOISE_COLUMN.band);

    if (chart && spectrumPointer !== null) {
        const band = Math.round(chart.convertFromPixel({ yAxisIndex: 0 }, spectrumPointer.y));

        if (band >= 0 && band < NOISE_BANDS.length) {
            return band;
        }
    }

    return levels.indexOf(Math.max(...levels.filter((value) => value !== null)));
}

function spectrumTooltipHtml(params) {
    const point = Array.isArray(params) ? params[0] : params;
    const row = noiseRowAt(point?.axisValue);

    if (!row || row[NOISE_COLUMN.laeq] === null) {
        return "";
    }

    const band = spectrumBandAt(row);
    const value = row[NOISE_COLUMN.band + band];
    const colours = palette();

    return (
        `<div style="font-weight:500;margin-bottom:4px;color:${colours.text}">` +
        `${stampFormat.format(new Date(row[NOISE_COLUMN.time]))}</div>` +
        `<div style="display:flex;align-items:center;gap:12px">` +
        `<span>${NOISE_BANDS[band]} Hz</span>` +
        `<span style="margin-left:auto;font-variant-numeric:tabular-nums;color:${colours.text}">` +
        `${value === null ? "n/a" : `${formatNumber(value, 1)} dB`}</span></div>`
    );
}

/** Paints the scale beside the strip's label with the ramp and its ends. */
function paintSpectrumScale(range) {
    const scale = document.querySelector("[data-spectrum-scale]");
    const low = document.querySelector("[data-spectrum-low]");
    const high = document.querySelector("[data-spectrum-high]");

    if (!scale || !low || !high || !range) {
        return;
    }

    scale.style.background = `linear-gradient(to right, ${spectrumRamp().join(", ")})`;
    low.textContent = formatNumber(range.low, 0);
    high.textContent = formatNumber(range.high, 0);
}

/**
 * The waterfall: one cell per slot and band on the same time axis as the
 * strips above, so zoom and crosshair carry over. A custom series rather
 * than a heatmap - ECharts' heatmap wants a category axis for time.
 */
function spectrumOption() {
    const colours = palette();
    const range = spectrumRange();
    // The noise rows' own slot width: it is the bucket the cells stand for.
    const width = typicalStep(noiseRows, NOISE_COLUMN.time) || 600000;
    // Pinned to the whole window, holes included: the cells alone would
    // stretch the axis over the stretch that has noise and misalign it
    // with every strip above.
    const first = noiseRows[0]?.[NOISE_COLUMN.time];
    const last = noiseRows[noiseRows.length - 1]?.[NOISE_COLUMN.time];

    paintSpectrumScale(range);

    const cells = range
        ? noiseRows.flatMap((row) =>
              NOISE_BANDS.map((_, band) => [
                  row[NOISE_COLUMN.time],
                  band,
                  row[NOISE_COLUMN.band + band],
              ]).filter((cell) => cell[2] !== null),
          )
        : [];

    return {
        animation: false,
        useUTC: true,
        grid: { ...GRID_SIDES, top: 12, bottom: 28 },
        // The band axis is a category axis, which ECharts would pick for
        // the pointer; the strips share time, so the pointer rides x.
        tooltip: { ...tooltipFrame(colours, spectrumTooltipHtml), axisPointer: { axis: "x" } },
        axisPointer: { snap: true },
        xAxis: { ...timeAxis(colours), min: first, max: last },
        yAxis: {
            type: "category",
            data: NOISE_BANDS,
            axisLine: { lineStyle: { color: colours.axis } },
            axisTick: { show: false },
            axisLabel: {
                color: colours.label,
                fontSize: 10,
                // Every third band: 25, 50, 100, 200 ... an octave apart.
                interval: 2,
                formatter: (label) => `${label} Hz`,
            },
            splitLine: { show: false },
        },
        series: [
            {
                type: "custom",
                // Edge cells stick out half a slot past the window.
                clip: true,
                data: cells,
                encode: { x: 0, y: 1 },
                renderItem: (params, api) => {
                    const [x, y] = api.coord([api.value(0), api.value(1)]);
                    const [cellWidth, cellHeight] = api.size([width, 1]);

                    return {
                        type: "rect",
                        shape: {
                            x: x - cellWidth / 2,
                            y: y - cellHeight / 2,
                            width: Math.max(1, cellWidth),
                            height: cellHeight,
                        },
                        style: { fill: spectrumColour(api.value(2), range) },
                    };
                },
            },
            // A custom series gives the axis tooltip nothing to snap to, so
            // it would neither show nor sync the crosshair: an invisible
            // line through every slot does.
            {
                type: "line",
                showSymbol: false,
                lineStyle: { opacity: 0 },
                data: noiseRows.map((row) => [row[NOISE_COLUMN.time], 0]),
            },
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
            data: rows.map((row) => [row[COLUMN.time], row[lowColumn]]),
        },
        {
            ...shared,
            name: `${channel.label} maximum`,
            areaStyle: { color: colourFor(channel.key), opacity: BAND_OPACITY },
            data: rows.map((row) => [row[COLUMN.time], spread(row)]),
        },
    ];
}

let overview = [];

let overviewChart = null;

let settingWindow = false;

let applied = { from: null, to: null };

function navigatorOption(from, to) {
    const colours = palette();

    return {
        animation: false,
        useUTC: true,
        grid: { ...GRID_SIDES, top: 4, height: 44 },
        // Two x axes: the slider narrows the one it drives to the window, so
        // that one is hidden and a second, pinned to the record's ends like
        // the shadow, carries the labels and the event lines.
        xAxis: [
            { type: "time", show: false },
            {
                type: "time",
                // A second x axis defaults to the opposite side.
                position: "bottom",
                min: "dataMin",
                max: "dataMax",
                axisLine: { lineStyle: { color: colours.axis } },
                axisTick: { show: false },
                axisLabel: {
                    color: colours.label,
                    fontSize: 10,
                    hideOverlap: true,
                    formatter: TIME_LABELS,
                },
                splitLine: { show: false },
            },
        ],
        yAxis: { type: "value", show: false, scale: true },
        dataZoom: [
            {
                type: "slider",
                xAxisIndex: 0,
                ...GRID_SIDES,
                top: 4,
                height: 44,
                startValue: from,
                endValue: to,
                showDetail: false,
                brushSelect: false,
                borderColor: "transparent",
                backgroundColor: "transparent",
                fillerColor: isDark() ? "#ffffff1a" : "#0000000f",
                handleStyle: {
                    color: colours.surface,
                    borderColor: colours.axis,
                },
                moveHandleStyle: { color: colours.axis },
                dataBackground: {
                    lineStyle: { color: colours.axis, width: 1 },
                    areaStyle: { color: "transparent" },
                },
                selectedDataBackground: {
                    lineStyle: { color: colourFor("t"), width: 1 },
                    areaStyle: { color: "transparent" },
                },
            },
        ],
        series: [
            {
                type: "line",
                xAxisIndex: 0,
                showSymbol: false,
                lineStyle: { width: 0 },
                data: overview.map((row) => [row[COLUMN.time], row[COLUMN.t]]),
            },
            // The same rows on the unzoomed axis, so labels and event lines span what the shadow does.
            {
                type: "line",
                xAxisIndex: 1,
                showSymbol: false,
                lineStyle: { width: 0 },
                data: overview.map((row) => [row[COLUMN.time], row[COLUMN.t]]),
                markLine: {
                    silent: true,
                    animation: false,
                    symbol: "none",
                    label: { show: false },
                    data: eventLines(),
                },
            },
        ],
    };
}

function bindNavigator(component) {
    let pending = null;

    overviewChart.on("datazoom", () => {
        // Echo of our own positioning.
        if (settingWindow) {
            return;
        }

        clearTimeout(pending);

        // Only the resting place is worth a query.
        pending = setTimeout(() => {
            const zoom = overviewChart.getOption().dataZoom?.[0];

            if (!zoom) {
                return;
            }

            const from = epochFromWallMs(overview, zoom.startValue);
            const to = epochFromWallMs(overview, zoom.endValue);

            if (from === null || to === null || from >= to) {
                return;
            }

            // Landed where it started.
            const unchanged =
                applied.from !== null &&
                Math.abs(from - applied.from) < 60 &&
                Math.abs(to - applied.to) < 60;

            if (!unchanged) {
                component.call("zoomTo", from, to);
            }
        }, 350);
    });
}

function mountNavigator(payload, component) {
    const element = document.querySelector("[data-navigator]");

    if (!element || overview.length === 0) {
        return;
    }

    const from = Number(payload.dataset.windowFrom);
    const to = Number(payload.dataset.windowTo);

    if (!overviewChart || overviewChart.getDom() !== element) {
        overviewChart?.dispose();
        overviewChart = echarts.init(element, null, { renderer: "canvas" });
        blockWheel(element);

        if (component) {
            bindNavigator(component);
        }
    }

    applied = {
        from: epochFromWallMs(overview, from),
        to: epochFromWallMs(overview, to),
    };

    settingWindow = true;
    overviewChart.setOption(navigatorOption(from, to), { notMerge: true });
    // The event can arrive after setOption returns.
    setTimeout(() => {
        settingWindow = false;
    }, 0);
}

/** ZRender's wheel listener swallows page scroll over a chart; stop it in capture, keep the browser default. */
function blockWheel(element) {
    element.addEventListener("wheel", (event) => event.stopPropagation(), {
        capture: true,
        passive: true,
    });
}

function epochAt(chart, clientX) {
    const box = chart.getDom().getBoundingClientRect();
    const time = chart.convertFromPixel({ xAxisIndex: 0 }, clientX - box.left);
    const row = rowAt(time);

    return row ? row[COLUMN.epoch] : null;
}

function bindZoom(chart, element, component) {
    let anchor = null;

    const band = element.querySelector("[data-zoom-band]");

    const paint = (from, to) => {
        if (!band) {
            return;
        }

        band.style.left = `${Math.min(from, to)}px`;
        band.style.width = `${Math.abs(to - from)}px`;
        band.hidden = false;
    };

    element.addEventListener("pointerdown", (event) => {
        if (event.button !== 0 || event.pointerType === "touch") {
            return;
        }

        anchor = {
            clientX: event.clientX,
            localX: event.clientX - element.getBoundingClientRect().left,
        };
        element.setPointerCapture(event.pointerId);
    });

    element.addEventListener("pointermove", (event) => {
        if (anchor) {
            paint(anchor.localX, event.clientX - element.getBoundingClientRect().left);
        }
    });

    const settle = (event) => {
        if (!anchor) {
            return;
        }

        const travelled = Math.abs(event.clientX - anchor.clientX);
        const from = epochAt(chart, anchor.clientX);
        const to = epochAt(chart, event.clientX);

        anchor = null;

        if (band) {
            band.hidden = true;
        }

        if (travelled >= DRAG_SLOP_PX && from !== null && to !== null && from !== to) {
            component.call("zoomTo", Math.min(from, to), Math.max(from, to));
        }
    };

    element.addEventListener("pointerup", settle);
    element.addEventListener("pointercancel", () => {
        anchor = null;

        if (band) {
            band.hidden = true;
        }
    });

    element.addEventListener("dblclick", () => component.call("resetZoom"));
}

let mounting = false;

let painted = null;

function paintKey(payload) {
    return (
        payload.dataset.chartRows +
        "\n" +
        payload.dataset.chartEvents +
        "\n" +
        payload.dataset.hiddenChannels +
        "\n" +
        payload.dataset.noiseRows
    );
}

function mount(force = false) {
    if (mounting) {
        return;
    }

    const payload = document.querySelector("[data-chart-rows]");

    if (!payload) {
        return;
    }

    mounting = true;

    try {
        render(payload, force);
    } finally {
        mounting = false;
    }
}

function render(payload, force) {
    try {
        rows = JSON.parse(payload.dataset.chartRows);
    } catch {
        rows = [];
    }

    step = typicalStep(rows);

    try {
        overview = JSON.parse(payload.dataset.navigatorRows);
    } catch {
        overview = [];
    }

    try {
        events = JSON.parse(payload.dataset.chartEvents);
    } catch {
        events = [];
    }

    try {
        hidden = new Set(JSON.parse(payload.dataset.hiddenChannels));
    } catch {
        hidden = new Set();
    }

    try {
        noiseRows = JSON.parse(payload.dataset.noiseRows ?? "[]");
    } catch {
        noiseRows = [];
    }

    const component = window.Livewire?.find(payload.dataset.chartComponent);

    mountNavigator(payload, component);

    // Most polls change nothing in this window; do not repaint under the pointer.
    if (!force && paintKey(payload) === painted) {
        return;
    }

    painted = paintKey(payload);

    // No mouseout comes for a line that is rebuilt.
    hovered = null;

    // The noise strips come and go with the window; a chart whose canvas left the page goes too.
    charts.forEach((chart, key) => {
        if (!document.body.contains(chart.getDom())) {
            chart.dispose();
            charts.delete(key);
        }
    });

    document.querySelectorAll("[data-strip]").forEach((element) => {
        const strip = STRIPS.find((candidate) => candidate.key === element.dataset.strip);

        if (!strip) {
            return;
        }

        let chart = charts.get(strip.key);

        if (chart && chart.getDom() !== element.querySelector("[data-canvas]")) {
            chart.dispose();
            chart = null;
        }

        if (!chart) {
            chart = echarts.init(element.querySelector("[data-canvas]"), null, {
                renderer: "canvas",
            });
            echarts.connect(GROUP);
            chart.group = GROUP;
            charts.set(strip.key, chart);
            blockWheel(element);
            trackEventHover(chart);

            if (strip.key === "spectrum") {
                trackSpectrumPointer(chart);
            }

            if (component) {
                bindZoom(chart, element, component);
            }
        }

        chart.setOption(strip.option(strip), { notMerge: true });
    });

    echarts.connect(GROUP);
}

function resize() {
    charts.forEach((chart) => chart.resize());
    overviewChart?.resize();
}

/**
 * Livewire rewrites the payload attributes on every poll. The navigator's
 * are watched too: a zoomed window's channel payload never changes while
 * the record keeps growing. Attributes only, never childList: ECharts
 * appends to the body on setOption and the observer would loop.
 */
function watchPayload() {
    new MutationObserver(() => mount()).observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: [
            "data-chart-rows",
            "data-navigator-rows",
            "data-chart-events",
            "data-hidden-channels",
            "data-noise-rows",
        ],
    });
}

/** Flux toggles `.dark` on the root; a canvas has to be repainted. */
function watchTheme() {
    let dark = isDark();

    new MutationObserver(() => {
        if (dark !== isDark()) {
            dark = isDark();
            mount(true);
        }
    }).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ["class"],
    });
}

document.addEventListener("DOMContentLoaded", () => {
    mount();
    watchPayload();
    watchTheme();
});

document.addEventListener("livewire:navigated", () => mount(true));
window.addEventListener("resize", resize);
