import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import { GridComponent, MarkLineComponent, TooltipComponent } from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";

echarts.use([LineChart, GridComponent, MarkLineComponent, TooltipComponent, CanvasRenderer]);

/**
 * How far one horizon's temperature forecast was off by the local hour it was
 * for: a line through all 24 hours of the mean reading minus the forecast's
 * middle, around a zero line - above it the station read warmer than forecast,
 * which is where a sun on the shield shows. The points stay neutral: their
 * height is the error, and a grade colour would describe another measure. An
 * hour with nothing scored is a gap.
 * Not a strip: no time axis, no zoom, no crosshair.
 */

/**
 * Rows are `[percent, forecast hours scored, mean and largest distance from
 * the forecast's middle in °C, mean reading minus the middle in °C]`, nulls
 * for an hour with none.
 */
const HOUR = { percent: 0, count: 1, error: 2, worst: 3, bias: 4 };

const celsius = new Intl.NumberFormat("cs-CZ", {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

/** Axis ticks: whole degrees print bare, a half as the tooltip prints it. */
const tick = new Intl.NumberFormat("cs-CZ", {
    maximumFractionDigits: 1,
    signDisplay: "exceptZero",
});

const charts = new Map();

const sizeObservers = new Map();

/** What each chart was last painted from; a poll that changes nothing repaints nothing. */
const painted = new Map();

function isDark() {
    return document.documentElement.classList.contains("dark");
}

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

const pad = (hour) => String(hour).padStart(2, "0");

/** Short lines, one fact each: ECharts keeps a tooltip on one line per `<br>`, and a phone is 320 px wide. */
function tooltipHtml(hour, row) {
    const span = `${pad(hour)}:00-${pad((hour + 1) % 24)}:00`;

    if (row[HOUR.percent] === null) {
        return `${span}<br>no forecast scored`;
    }

    const hours = row[HOUR.count] === 1 ? "1 forecast hour" : `${row[HOUR.count]} forecast hours`;

    const bias = row[HOUR.bias];
    // Judged as printed: 0.04 would read "0,0 °C warmer".
    const side =
        Math.round(bias * 10) === 0
            ? "as forecast on average"
            : `${celsius.format(Math.abs(bias))} °C ${bias > 0 ? "warmer" : "colder"} on average`;

    return (
        `${span}<br><strong>${side}</strong>` +
        `<br>off by ${celsius.format(row[HOUR.error])} °C on average` +
        `<br>off by ${celsius.format(row[HOUR.worst])} °C at most` +
        `<br>${row[HOUR.percent]} % in range<br>${hours}`
    );
}

/** Room kept between the tooltip and the pointer, and between the tooltip and the screen's edge. */
const TOOLTIP_GAP = 12;
const SCREEN_EDGE = 8;

/**
 * Above the pointer, where a finger does not cover it, on whichever side has
 * room, and never past the screen. The table scrolls sideways, so the chart
 * can be wider than the screen: ECharts' own flip and `confine` both measure
 * the chart and left the tooltip half off a phone.
 */
function besidePointer(canvas) {
    return ([x, y], params, dom, rect, { contentSize: [width, height] }) => {
        const box = canvas.getBoundingClientRect();
        const leftmost = SCREEN_EDGE - box.left;
        const rightmost = document.documentElement.clientWidth - SCREEN_EDGE - box.left - width;
        const beside = x + TOOLTIP_GAP <= rightmost ? x + TOOLTIP_GAP : x - TOOLTIP_GAP - width;
        const above = y - TOOLTIP_GAP - height;

        return [
            Math.max(leftmost, Math.min(beside, rightmost)),
            box.top + above >= SCREEN_EDGE ? above : y + TOOLTIP_GAP,
        ];
    };
}

/**
 * Whole degrees either side of zero, at least one, so warmer and colder read
 * alike. With nothing to plot ECharts hands over infinities; one it is.
 */
function extent({ min, max }) {
    const furthest = Math.max(Math.abs(min), Math.abs(max));

    return Number.isFinite(furthest) ? Math.max(1, Math.ceil(furthest)) : 1;
}

function signedDegrees(value) {
    return `${tick.format(value)} °C`;
}

function option(rows, canvas) {
    const colours = palette();

    return {
        animation: false,
        grid: { left: 44, right: 8, top: 8, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row, hour) => pad(hour)),
            boundaryGap: false,
            // At the bottom, not on zero: the zero line is the series' own markLine.
            axisLine: { onZero: false, lineStyle: { color: colours.axis } },
            axisTick: { alignWithLabel: true, lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontFamily: "IBM Plex Mono",
                fontSize: 10,
                interval: 2,
            },
        },
        yAxis: {
            type: "value",
            min: (range) => -extent(range),
            max: (range) => extent(range),
            splitNumber: 2,
            axisLabel: {
                color: colours.label,
                fontFamily: "IBM Plex Mono",
                fontSize: 10,
                formatter: signedDegrees,
            },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        tooltip: {
            trigger: "axis",
            // The table scrolls sideways, and its overflow would clip a tooltip kept inside.
            appendTo: "body",
            position: besidePointer(canvas),
            axisPointer: { type: "line", lineStyle: { color: colours.axis } },
            backgroundColor: colours.surface,
            borderColor: colours.border,
            textStyle: { color: colours.text, fontFamily: "IBM Plex Mono", fontSize: 11 },
            formatter: ([point]) => tooltipHtml(point.dataIndex, rows[point.dataIndex]),
        },
        series: [
            {
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 6,
                lineStyle: { color: colours.label, width: 1.5 },
                itemStyle: { color: colours.label },
                markLine: {
                    silent: true,
                    symbol: "none",
                    label: { show: false },
                    lineStyle: { color: colours.axis, type: "solid", width: 1 },
                    data: [{ yAxis: 0 }],
                },
                data: rows.map((row) => row[HOUR.bias]),
            },
        ],
    };
}

/** The panel opens folded, so a chart starts at 0x0 and needs the observer to grow into its box. */
function watchSize(key, chart, element) {
    const observer = new ResizeObserver(() => chart.resize());

    observer.observe(element);
    sizeObservers.set(key, observer);
}

function dispose(key) {
    painted.delete(key);
    sizeObservers.get(key)?.disconnect();
    sizeObservers.delete(key);
    charts.get(key)?.dispose();
    charts.delete(key);
}

function mount(force = false) {
    // The forecast block comes and goes with the record; a chart whose canvas left the page goes too.
    charts.forEach((chart, key) => {
        if (!document.body.contains(chart.getDom())) {
            dispose(key);
        }
    });

    document.querySelectorAll("[data-accuracy-chart]").forEach((element) => {
        const key = element.dataset.accuracyChart;
        const canvas = element.querySelector("[data-accuracy-canvas]");
        let chart = charts.get(key);

        if (chart && chart.getDom() !== canvas) {
            dispose(key);
            chart = null;
        }

        if (!chart) {
            chart = echarts.init(canvas, null, { renderer: "canvas" });
            charts.set(key, chart);
            watchSize(key, chart, canvas);
        }

        if (!force && painted.get(key) === element.dataset.accuracyHours) {
            return;
        }

        let rows;

        try {
            rows = JSON.parse(element.dataset.accuracyHours);
        } catch {
            rows = [];
        }

        painted.set(key, element.dataset.accuracyHours);
        chart.setOption(option(rows, canvas), { notMerge: true });
    });
}

/**
 * After every morph of the dashboard: a poll may rewrite a chart's rows or
 * bring the whole forecast block back. A hook, not a MutationObserver on the
 * body - that one would fire on every tooltip ECharts redraws.
 */
function watchMorphs() {
    const hook = () => window.Livewire.hook("morphed", () => mount());

    if (window.Livewire) {
        hook();
    } else {
        document.addEventListener("livewire:init", hook);
    }
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
    watchMorphs();
    watchTheme();
});

document.addEventListener("livewire:navigated", () => mount(true));
