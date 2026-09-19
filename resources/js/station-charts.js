// Piecemeal imports: the full ECharts bundle is ~400 kB gzipped.
import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import {
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    MarkLineComponent,
    TooltipComponent,
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";

echarts.use([
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
    { key: "th", channels: ["t", "h", "d"] },
    { key: "p", channels: ["p"] },
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

/** Event row: wall-clock ms, title, CSS colour or null. */
const EVENT = { time: 0, title: 1, colour: 2 };

/** Fallback event colour; a mid-grey that works on both grounds. */
const EVENT_COLOUR = "#a1a1aa";

/** Shared by every canvas so the stacked time axes line up pixel for pixel; the right margin is a second value axis's width. */
const GRID_SIDES = { left: 64, right: 64 };

const charts = new Map();

let rows = [];

let events = [];

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

function nearestRow(list, time) {
    if (list.length === 0) {
        return null;
    }

    let low = 0;
    let high = list.length - 1;

    while (low < high) {
        const mid = (low + high) >> 1;

        if (list[mid][COLUMN.time] < time) {
            low = mid + 1;
        } else {
            high = mid;
        }
    }

    const after = list[low];
    const before = list[Math.max(0, low - 1)];

    return Math.abs(before[COLUMN.time] - time) <= Math.abs(after[COLUMN.time] - time)
        ? before
        : after;
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

function typicalStep(list) {
    if (list.length < 2) {
        return 0;
    }

    const gaps = [];

    for (let index = 1; index < list.length; index++) {
        gaps.push(list[index][COLUMN.time] - list[index - 1][COLUMN.time]);
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
        tooltip: {
            trigger: "axis",
            appendToBody: true,
            backgroundColor: colours.surface,
            borderColor: colours.border,
            textStyle: { color: colours.label, fontSize: 12 },
            formatter: tooltipHtml,
        },
        axisPointer: { snap: true },
        xAxis: {
            type: "time",
            axisLine: { lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontSize: 10,
                hideOverlap: true,
                formatter: TIME_LABELS,
            },
            splitLine: { show: true, lineStyle: { color: colours.grid } },
        },
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
        payload.dataset.hiddenChannels
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

    const component = window.Livewire?.find(payload.dataset.chartComponent);

    mountNavigator(payload, component);

    // Most polls change nothing in this window; do not repaint under the pointer.
    if (!force && paintKey(payload) === painted) {
        return;
    }

    painted = paintKey(payload);

    // No mouseout comes for a line that is rebuilt.
    hovered = null;

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

            if (component) {
                bindZoom(chart, element, component);
            }
        }

        chart.setOption(optionFor(strip), { notMerge: true });
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
