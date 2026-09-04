// Pulled in piece by piece: the whole ECharts bundle is ~400 kB gzipped, and
// the station draws lines on a grid and nothing else.
import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import {
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    TooltipComponent,
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";

echarts.use([
    LineChart,
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    TooltipComponent,
    CanvasRenderer,
]);

/**
 * The three channel charts.
 *
 * One ECharts instance per channel, joined into a group so that the crosshair
 * and the zoom track together. Each chart draws a single series but its
 * tooltip reads every channel, so hovering anywhere reports the whole station
 * at that instant.
 *
 * Dragging across a chart selects a window; releasing hands the two real
 * timestamps back to Livewire, which re-queries at a resolution that suits the
 * new span. Zooming is therefore a server round trip rather than a rescale of
 * what is already loaded - which is what makes zooming into a thinned year
 * come back with the readings that were skipped.
 */

const GROUP = "station";

/** Long enough to be a drag rather than a slipped click. */
const DRAG_SLOP_PX = 6;

const CHANNELS = [
    { key: "t", label: "Temperature", unit: "°C", decimals: 2 },
    { key: "h", label: "Humidity", unit: "%", decimals: 2 },
    { key: "p", label: "Pressure", unit: "hPa", decimals: 1 },
];

/**
 * Tick labels per time unit.
 *
 * Left to itself ECharts prints a bare day number, which reads as a quantity
 * rather than a date - "10" tells you nothing about which month it sits in.
 * Naming a format for every unit keeps the axis legible however far it is
 * zoomed. Every one of them is numeric on purpose: month names would be the
 * only words on the axis, and would have to be pinned to a language the rest
 * of the page never states.
 */
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

/** Row layout from the server: wall-clock ms, °C, %, hPa, real epoch seconds. */
const COLUMN = { time: 0, t: 1, h: 2, p: 3, epoch: 4 };

const charts = new Map();

let rows = [];

function isDark() {
    return document.documentElement.classList.contains("dark");
}

/** Two palettes rather than CSS variables: ECharts paints onto a canvas. */
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
    p: { light: "#7c3aed", dark: "#a78bfa" },
};

function colourFor(key) {
    return LINE_COLOUR[key][isDark() ? "dark" : "light"];
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

/**
 * Wall-clock stamps are tagged UTC on purpose (see wallClockMs() on the
 * component), so every formatter here must read them as UTC too.
 */
const stampFormat = new Intl.DateTimeFormat("cs-CZ", {
    day: "numeric",
    month: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "UTC",
});

/** The row nearest an axis value. */
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

/**
 * A wall-clock millisecond back to the real epoch the server understands.
 *
 * The offset is not a constant - it is an hour in winter and two in summer -
 * so it is read off the nearest row, which carries both readings of the same
 * instant. That keeps a window dragged across a DST change honest.
 */
function epochFromWallMs(list, milliseconds) {
    const row = nearestRow(list, milliseconds);

    if (!row) {
        return null;
    }

    const offsetSeconds = Math.round(row[COLUMN.time] / 1000) - row[COLUMN.epoch];

    return Math.round(milliseconds / 1000) - offsetSeconds;
}

function tooltipHtml(params) {
    const point = Array.isArray(params) ? params[0] : params;
    const row = rowAt(point?.axisValue);

    if (!row) {
        return "";
    }

    const colours = palette();
    const heading =
        `<div style="font-weight:500;margin-bottom:4px;color:${colours.text}">` +
        `${stampFormat.format(new Date(row[COLUMN.time]))}</div>`;

    const lines = CHANNELS.map((channel) => {
        const dot =
            `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;` +
            `background:${colourFor(channel.key)};margin-right:6px"></span>`;

        return (
            `<div style="display:flex;align-items:center;gap:12px">` +
            `<span>${dot}${channel.label}</span>` +
            `<span style="margin-left:auto;font-variant-numeric:tabular-nums;color:${colours.text}">` +
            `${formatNumber(row[COLUMN[channel.key]], channel.decimals)} ${channel.unit}</span>` +
            `</div>`
        );
    }).join("");

    return heading + lines;
}

function optionFor(channel) {
    const colours = palette();

    return {
        animation: false,
        // Stamps carry the station's local offset already; reading them as UTC
        // is what keeps the axis on Czech time for every viewer.
        useUTC: true,
        grid: { left: 64, right: 16, top: 12, bottom: 28 },
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
        yAxis: {
            type: "value",
            scale: true,
            axisLabel: { color: colours.label, fontSize: 10 },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: [
            {
                type: "line",
                name: channel.label,
                showSymbol: false,
                lineStyle: { width: 1.5, color: colourFor(channel.key) },
                itemStyle: { color: colourFor(channel.key) },
                data: rows.map((row) => [row[COLUMN.time], row[COLUMN[channel.key]]]),
            },
        ],
    };
}

let overview = [];

let overviewChart = null;

/** True while the slider is being positioned from the server's answer. */
let settingWindow = false;

/** The window the server last gave us, to recognise an echo of it. */
let applied = { from: null, to: null };

function navigatorOption(from, to) {
    const colours = palette();

    return {
        animation: false,
        useUTC: true,
        // The slider draws its own shadow of the data, so the plot area adds
        // nothing but the room the axis labels need beneath it.
        grid: { left: 64, right: 16, top: 4, height: 44 },
        xAxis: {
            type: "time",
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
        yAxis: { type: "value", show: false, scale: true },
        dataZoom: [
            {
                type: "slider",
                left: 64,
                right: 16,
                top: 4,
                height: 44,
                startValue: from,
                endValue: to,
                showDetail: false,
                brushSelect: false,
                borderColor: "transparent",
                backgroundColor: "transparent",
                fillerColor: isDark() ? "#ffffff1a" : "#0000000f",
                handleStyle: { color: colours.surface, borderColor: colours.axis },
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
                showSymbol: false,
                lineStyle: { width: 0 },
                data: overview.map((row) => [row[COLUMN.time], row[COLUMN.t]]),
            },
        ],
    };
}

function bindNavigator(component) {
    let pending = null;

    overviewChart.on("datazoom", () => {
        // Ignore the echo of positioning the slider ourselves.
        if (settingWindow) {
            return;
        }

        clearTimeout(pending);

        // Dragging fires continuously; only the resting place is worth a query.
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

            // Slider positions round; a drag that lands back where it started
            // must not bounce a request off the server.
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
    // The event can arrive after setOption returns, so the guard is lifted a
    // tick later rather than on the next line.
    setTimeout(() => {
        settingWindow = false;
    }, 0);
}

/**
 * Let the page keep the wheel.
 *
 * ZRender binds its own wheel listener to the canvas, which swallowed the
 * scroll whenever the pointer crossed a chart. Stopping the event here in the
 * capture phase means it never reaches that listener, while the browser's
 * default - scrolling the page - is left untouched.
 */
function blockWheel(element) {
    element.addEventListener("wheel", (event) => event.stopPropagation(), {
        capture: true,
        passive: true,
    });
}

/** Map a pixel column back to the real epoch the server understands. */
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

function mount() {
    if (mounting) {
        return;
    }

    const payload = document.querySelector("[data-chart-rows]");

    if (!payload) {
        return;
    }

    mounting = true;

    try {
        render(payload);
    } finally {
        mounting = false;
    }
}

function render(payload) {
    try {
        rows = JSON.parse(payload.dataset.chartRows);
    } catch {
        rows = [];
    }

    try {
        overview = JSON.parse(payload.dataset.navigatorRows);
    } catch {
        overview = [];
    }

    const component = window.Livewire?.find(payload.dataset.chartComponent);

    mountNavigator(payload, component);

    document.querySelectorAll("[data-channel]").forEach((element) => {
        const channel = CHANNELS.find((candidate) => candidate.key === element.dataset.channel);

        if (!channel) {
            return;
        }

        let chart = charts.get(channel.key);

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
            charts.set(channel.key, chart);
            blockWheel(element);

            if (component) {
                bindZoom(chart, element, component);
            }
        }

        chart.setOption(optionFor(channel), { notMerge: true });
    });

    echarts.connect(GROUP);
}

function resize() {
    charts.forEach((chart) => chart.resize());
    overviewChart?.resize();
}

/**
 * Livewire rewrites the payload attribute on every window change.
 *
 * Attributes only - never childList. ECharts appends its tooltip to the body
 * and repaints on `setOption`, so an observer watching for added nodes would
 * be re-triggered by the very mount it just ran, and the page would lock up.
 */
function watchPayload() {
    new MutationObserver(mount).observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ["data-chart-rows"],
    });
}

/** Flux toggles `.dark` on the root element; the canvas has to be repainted. */
function watchTheme() {
    let dark = isDark();

    new MutationObserver(() => {
        if (dark !== isDark()) {
            dark = isDark();
            mount();
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

document.addEventListener("livewire:navigated", mount);
window.addEventListener("resize", resize);
