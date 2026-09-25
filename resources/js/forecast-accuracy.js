import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import { GridComponent, TooltipComponent } from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";

echarts.use([LineChart, GridComponent, TooltipComponent, CanvasRenderer]);

/**
 * The temperature accuracy of one horizon by the local hour the forecast was
 * for: a line through all 24 hours, each point coloured by the grade the
 * server gives it (AccuracyGrade), so the points and the figures in the row
 * above read on one scale. An hour with nothing scored is a gap in the line.
 * Not a strip: no time axis, no zoom, no crosshair.
 */

/**
 * Rows are `[percent, forecast hours scored, grade, mean and largest distance
 * from the forecast's middle in °C]`, nulls for an hour with none.
 */
const HOUR = { percent: 0, count: 1, grade: 2, error: 3, worst: 4 };

const celsius = new Intl.NumberFormat("cs-CZ", {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

/** The Tailwind colours x-accuracy-percent prints the figures in. */
const GRADE_COLOUR = {
    good: { light: "#059669", dark: "#34d399" },
    fair: { light: "#d97706", dark: "#f59e0b" },
    poor: { light: "#dc2626", dark: "#f87171" },
};

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

function tooltipHtml(hour, row) {
    const span = `${pad(hour)}:00-${pad((hour + 1) % 24)}:00`;

    if (row[HOUR.percent] === null) {
        return `${span}<br>no forecast scored`;
    }

    const hours = row[HOUR.count] === 1 ? "1 forecast hour" : `${row[HOUR.count]} forecast hours`;

    return (
        `${span}<br><strong>${row[HOUR.percent]} %</strong> in range · ${hours}` +
        `<br>off by ${celsius.format(row[HOUR.error])} °C on average, ${celsius.format(row[HOUR.worst])} °C at most`
    );
}

function option(rows) {
    const colours = palette();
    const tone = isDark() ? "dark" : "light";

    return {
        animation: false,
        grid: { left: 36, right: 8, top: 8, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row, hour) => pad(hour)),
            boundaryGap: false,
            axisLine: { lineStyle: { color: colours.axis } },
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
            min: 0,
            max: 100,
            interval: 50,
            axisLabel: {
                color: colours.label,
                fontFamily: "IBM Plex Mono",
                fontSize: 10,
                formatter: "{value} %",
            },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        tooltip: {
            trigger: "axis",
            // The table scrolls sideways, and its overflow would clip a tooltip kept inside.
            appendTo: "body",
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
                data: rows.map((row) => ({
                    value: row[HOUR.percent],
                    itemStyle: {
                        color: row[HOUR.grade] ? GRADE_COLOUR[row[HOUR.grade]][tone] : colours.axis,
                    },
                })),
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
        chart.setOption(option(rows), { notMerge: true });
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
