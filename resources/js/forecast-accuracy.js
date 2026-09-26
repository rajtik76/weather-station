import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import { GridComponent, MarkLineComponent, TooltipComponent } from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";

echarts.use([LineChart, GridComponent, MarkLineComponent, TooltipComponent, CanvasRenderer]);

/**
 * The accuracy panel's two charts for the chosen horizon, told apart by
 * `data-accuracy-chart`. Not strips: no time axis, no zoom, no crosshair.
 *
 * `days`: the skill by the local day the forecasts were made - how much
 * smaller their miss was than the naive guess's, around a zero line where a
 * forecast is no better than the guess. Two lines: the forecast as shown, in
 * the temperature's amber, and the base model before the station correction,
 * dashed grey, so the gap between them is what the correction has learnt.
 * A day a new model or a new version of the correction took over gets a
 * dashed upright, labelled with which. A day with nothing
 * scored is a gap. The axis stops at -100 %: a calm day with a guess that
 * barely missed can score -1000 % and would flatten every other day; the
 * tooltip still prints the real figure.
 *
 * `hours`: how far the shown forecast was off by the local hour it was for:
 * the mean reading minus the forecast's middle, around a zero line - above it
 * the station read warmer than forecast, which is where a sun on the shield
 * shows. The points stay neutral: their height is the error, and a grade
 * colour would describe another measure.
 */

/**
 * A day row is `[date, forecast hours scored, skill in %, mean miss and mean
 * naive miss in °C, percent in range, mean range width in °C, the base
 * model's skill, mean miss, percent in range and range width, the model that
 * took over or null, the correction version that took over or null]`, nulls
 * but the date and the changes for a day with none.
 */
const DAY = {
    date: 0,
    count: 1,
    skill: 2,
    error: 3,
    naive: 4,
    percent: 5,
    width: 6,
    baseSkill: 7,
    baseError: 8,
    basePercent: 9,
    baseWidth: 10,
    tookOver: 11,
    correctionTo: 12,
};

/** Where the skill axis stops below zero. */
const SKILL_FLOOR = -100;

/**
 * An hour row is `[percent, forecast hours scored, mean and largest distance
 * from the forecast's middle in °C, mean reading minus the middle in °C]`,
 * nulls for an hour with none.
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
              temperature: "#f59e0b",
          }
        : {
              axis: "#d4d4d8",
              label: "#a1a1aa",
              grid: "#0000000d",
              surface: "#ffffff",
              border: "#e4e4e7",
              text: "#27272a",
              temperature: "#d97706",
          };
}

const pad = (hour) => String(hour).padStart(2, "0");

const forecastHours = (count) => (count === 1 ? "1 forecast hour" : `${count} forecast hours`);

/** Short lines, one fact each: ECharts keeps a tooltip on one line per `<br>`, and a phone is 320 px wide. */
/** "27 % better", "12 % worse", "as good": against the naive guess. */
function versus(skill) {
    if (skill === null) {
        return "no guess to beat";
    }

    return skill === 0
        ? "as good as the guess"
        : `${Math.abs(skill)} % ${skill > 0 ? "better" : "worse"}`;
}

function dayTooltipHtml(row) {
    const tookOver =
        (row[DAY.tookOver] === null ? "" : `<br>model trained ${row[DAY.tookOver]} took over`) +
        (row[DAY.correctionTo] === null ? "" : `<br>correction ${row[DAY.correctionTo]} took over`);

    if (row[DAY.count] === 0) {
        return `${row[DAY.date]}<br>no forecast scored${tookOver}`;
    }

    // In range is set whenever the base was scored; skill and miss need a naive guess too.
    const hasBase = row[DAY.basePercent] !== null;
    const lines = [row[DAY.date], `<strong>with correction ${versus(row[DAY.skill])}</strong>`];

    if (hasBase) {
        lines.push(`base model ${versus(row[DAY.baseSkill])}`);
    }

    if (row[DAY.error] !== null) {
        const base =
            hasBase && row[DAY.baseError] !== null
                ? `, base ${celsius.format(row[DAY.baseError])}`
                : "";
        lines.push(
            `off by ${celsius.format(row[DAY.error])}${base}, guess ${celsius.format(row[DAY.naive])} °C`,
        );
    }

    lines.push(
        `${row[DAY.percent]} % in range${hasBase ? `, base ${row[DAY.basePercent]} %` : ""}`,
    );
    lines.push(
        `${celsius.format(row[DAY.width])}${hasBase ? `, base ${celsius.format(row[DAY.baseWidth])}` : ""} °C wide`,
    );
    lines.push(forecastHours(row[DAY.count]));

    return lines.join("<br>") + tookOver;
}

function hourTooltipHtml(hour, row) {
    const span = `${pad(hour)}:00-${pad((hour + 1) % 24)}:00`;

    if (row[HOUR.percent] === null) {
        return `${span}<br>no forecast scored`;
    }

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
        `<br>${row[HOUR.percent]} % in range<br>${forecastHours(row[HOUR.count])}`
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

function signedPercent(value) {
    return `${tick.format(value)} %`;
}

/**
 * Whole tens, zero always in view: a skill that never went below it still
 * shows how far above it stayed. Never under SKILL_FLOOR.
 */
const tensBelow = ({ min }) =>
    Number.isFinite(min) ? Math.max(SKILL_FLOOR, Math.min(0, Math.floor(min / 10) * 10)) : 0;

const tensAbove = ({ max }) => (Number.isFinite(max) ? Math.max(10, Math.ceil(max / 10) * 10) : 10);

/** Axis ticks under a full date: the year once is enough. */
const dayTick = (date) => date.replace(/\d{4}$/, "");

function tooltip(colours, canvas, formatter) {
    return {
        trigger: "axis",
        // The table scrolls sideways, and its overflow would clip a tooltip kept inside.
        appendTo: "body",
        position: besidePointer(canvas),
        axisPointer: { type: "line", lineStyle: { color: colours.axis } },
        backgroundColor: colours.surface,
        borderColor: colours.border,
        textStyle: { color: colours.text, fontFamily: "IBM Plex Mono", fontSize: 11 },
        // Both series share the day: one tooltip for the row, whichever line the pointer is on.
        formatter: ([point]) => formatter(point.dataIndex),
    };
}

function zeroLine(colours) {
    return { yAxis: 0, lineStyle: { color: colours.axis, type: "solid", width: 1 } };
}

function daysOption(rows, canvas) {
    const colours = palette();

    return {
        animation: false,
        // Room on top for the retrain labels.
        grid: { left: 44, right: 8, top: 20, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row) => row[DAY.date]),
            boundaryGap: false,
            axisLine: { onZero: false, lineStyle: { color: colours.axis } },
            axisTick: { alignWithLabel: true, lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontFamily: "IBM Plex Mono",
                fontSize: 10,
                hideOverlap: true,
                formatter: dayTick,
            },
        },
        yAxis: {
            type: "value",
            min: tensBelow,
            max: tensAbove,
            splitNumber: 2,
            axisLabel: {
                color: colours.label,
                fontFamily: "IBM Plex Mono",
                fontSize: 10,
                formatter: signedPercent,
            },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        tooltip: tooltip(colours, canvas, (index) => dayTooltipHtml(rows[index])),
        series: [
            {
                name: "base",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 6,
                lineStyle: { color: colours.label, width: 1.5, type: "dashed" },
                itemStyle: { color: colours.label },
                data: rows.map((row) => row[DAY.baseSkill]),
            },
            {
                name: "corrected",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 8,
                lineStyle: { color: colours.temperature, width: 2 },
                itemStyle: { color: colours.temperature },
                markLine: {
                    silent: true,
                    symbol: "none",
                    label: {
                        color: colours.label,
                        fontFamily: "IBM Plex Mono",
                        fontSize: 10,
                        position: "end",
                    },
                    data: [
                        { ...zeroLine(colours), label: { show: false } },
                        ...rows
                            .filter((row) => changeLabel(row) !== "")
                            .map((row) => ({
                                xAxis: row[DAY.date],
                                label: { formatter: changeLabel(row) },
                                lineStyle: { color: colours.label, type: "dashed", width: 1 },
                            })),
                    ],
                },
                data: rows.map((row) => row[DAY.skill]),
            },
        ],
    };
}

/** "retrained", "correction 2", or both when they came the same day; empty for a day of neither. */
function changeLabel(row) {
    return [
        row[DAY.tookOver] === null ? null : "retrained",
        row[DAY.correctionTo] === null ? null : `correction ${row[DAY.correctionTo]}`,
    ]
        .filter(Boolean)
        .join(", ");
}

function hoursOption(rows, canvas) {
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
        tooltip: tooltip(colours, canvas, (index) => hourTooltipHtml(index, rows[index])),
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
                    data: [zeroLine(colours)],
                },
                data: rows.map((row) => row[HOUR.bias]),
            },
        ],
    };
}

const OPTIONS = { days: daysOption, hours: hoursOption };

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

        if (!force && painted.get(key) === element.dataset.accuracyRows) {
            return;
        }

        let rows;

        try {
            rows = JSON.parse(element.dataset.accuracyRows);
        } catch {
            rows = [];
        }

        painted.set(key, element.dataset.accuracyRows);
        chart.setOption(OPTIONS[key](rows, canvas), { notMerge: true });
    });
}

/**
 * After every morph of the dashboard: a poll or a horizon switch may rewrite a
 * chart's rows, or a poll bring the whole forecast block back. A hook, not a MutationObserver on the
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
