import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import { GridComponent, MarkLineComponent, TooltipComponent } from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import { formatNumber } from "./charts/format";
import { parsed, unwatchSize, watchSize, watchThemeChange } from "./charts/lifecycle";
import { CHART_FONT, basePalette, token } from "./charts/theme";

echarts.use([LineChart, GridComponent, MarkLineComponent, TooltipComponent, CanvasRenderer]);

/** Forecast page accuracy charts (`data-accuracy-chart`: days, hours, widths). Not strips: no time axis, zoom or crosshair. */

/** Day row; skill in %, misses and widths in °C. Nulls but the date and the changes for a day with nothing scored. */
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

// A calm day can score -1000 % and would flatten the rest; the tooltip prints the real figure.
const SKILL_FLOOR = -100;

/** Hour row; errors and bias in °C (bias: mean reading minus the forecast's middle). Nulls for an hour with none. */
const HOUR = { percent: 0, count: 1, error: 2, worst: 3, bias: 4 };

const celsius = { format: (value) => formatNumber(value, 1) };

const tick = new Intl.NumberFormat("cs-CZ", {
    maximumFractionDigits: 1,
    signDisplay: "exceptZero",
});

const width = new Intl.NumberFormat("cs-CZ", { maximumFractionDigits: 1 });

const charts = new Map();

/** Last painted payload per chart; an unchanged poll repaints nothing. */
const painted = new Map();

function palette() {
    return { ...basePalette(), temperature: token("--ch1") };
}

const pad = (hour) => String(hour).padStart(2, "0");

const forecastHours = (count) => (count === 1 ? "1 forecast hour" : `${count} forecast hours`);

function versus(skill) {
    if (skill === null) {
        return "no guess to beat";
    }

    return skill === 0
        ? "as good as the guess"
        : `${Math.abs(skill)} % ${skill > 0 ? "better" : "worse"}`;
}

/** Short lines, one fact each: a phone is 320 px wide. */
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

const TOOLTIP_GAP = 12;
const SCREEN_EDGE = 8;

/** Above the pointer, never past the screen: the chart can be wider than the screen, so ECharts' flip and `confine` left the tooltip half off a phone. */
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

/** Whole degrees either side of zero, at least one; ECharts hands over infinities when there is nothing to plot. */
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

/** Whole tens, zero always in view, never under SKILL_FLOOR. */
const tensBelow = ({ min }) =>
    Number.isFinite(min) ? Math.max(SKILL_FLOOR, Math.min(0, Math.floor(min / 10) * 10)) : 0;

const tensAbove = ({ max }) => (Number.isFinite(max) ? Math.max(10, Math.ceil(max / 10) * 10) : 10);

const dayTick = (date) => date.replace(/\d{4}$/, "");

function tooltip(colours, canvas, formatter) {
    return {
        trigger: "axis",
        // The table's overflow would clip a tooltip kept inside.
        appendTo: "body",
        position: besidePointer(canvas),
        axisPointer: { type: "line", lineStyle: { color: colours.axis } },
        backgroundColor: colours.surface,
        borderColor: colours.border,
        extraCssText:
            "backdrop-filter: blur(12px); border-radius: 12px; box-shadow: 0 8px 24px rgb(15 28 46 / 0.14);",
        textStyle: { color: colours.text, fontFamily: CHART_FONT, fontSize: 11 },
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
        grid: { left: 44, right: 8, top: 20, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row) => row[DAY.date]),
            boundaryGap: false,
            axisLine: { onZero: false, lineStyle: { color: colours.axis } },
            axisTick: { alignWithLabel: true, lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
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
                fontFamily: CHART_FONT,
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
                markLine: changeMarks(rows, colours, [
                    { ...zeroLine(colours), label: { show: false } },
                ]),
                data: rows.map((row) => row[DAY.skill]),
            },
        ],
    };
}

function changeMarks(rows, colours, extra = []) {
    return {
        silent: true,
        symbol: "none",
        label: {
            color: colours.label,
            fontFamily: CHART_FONT,
            fontSize: 10,
            position: "end",
        },
        data: [
            ...extra,
            ...rows
                .filter((row) => changeLabel(row) !== "")
                .map((row) => ({
                    xAxis: row[DAY.date],
                    label: { formatter: changeLabel(row) },
                    lineStyle: { color: colours.label, type: "dashed", width: 1 },
                })),
        ],
    };
}

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
            // Bottom, not on zero: the zero line is the series' markLine.
            axisLine: { onZero: false, lineStyle: { color: colours.axis } },
            axisTick: { alignWithLabel: true, lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
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
                fontFamily: CHART_FONT,
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

function widthsOption(rows, canvas) {
    const colours = palette();

    return {
        animation: false,
        grid: { left: 44, right: 8, top: 20, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row) => row[DAY.date]),
            boundaryGap: false,
            axisLine: { lineStyle: { color: colours.axis } },
            axisTick: { alignWithLabel: true, lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
                fontSize: 10,
                hideOverlap: true,
                formatter: dayTick,
            },
        },
        yAxis: {
            type: "value",
            min: 0,
            max: ({ max }) => (Number.isFinite(max) ? Math.max(1, Math.ceil(max)) : 1),
            splitNumber: 2,
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
                fontSize: 10,
                formatter: (value) => `${width.format(value)} °C`,
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
                data: rows.map((row) => row[DAY.baseWidth]),
            },
            {
                name: "corrected",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 8,
                lineStyle: { color: colours.temperature, width: 2 },
                itemStyle: { color: colours.temperature },
                markLine: changeMarks(rows, colours),
                data: rows.map((row) => row[DAY.width]),
            },
        ],
    };
}

const OPTIONS = { days: daysOption, hours: hoursOption, widths: widthsOption };

function dispose(key) {
    painted.delete(key);
    unwatchSize(key);
    charts.get(key)?.dispose();
    charts.delete(key);
}

function mount(force = false) {
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

        const payload = element.dataset.accuracyRows;

        if (!force && painted.get(key) === payload) {
            return;
        }

        painted.set(key, payload);
        chart.setOption(OPTIONS[key](parsed(element.dataset.accuracyRows), canvas), {
            notMerge: true,
        });
    });
}

/** Runs after every morph. A hook, not a MutationObserver on the body: that would fire on every tooltip redraw. */
function watchMorphs() {
    const hook = () => window.Livewire.hook("morphed", () => mount());

    if (window.Livewire) {
        hook();
    } else {
        document.addEventListener("livewire:init", hook);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    mount();
    watchMorphs();
    watchThemeChange(() => mount(true));
});

document.addEventListener("livewire:navigated", () => mount(true));
