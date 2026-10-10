import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import { GridComponent, MarkLineComponent, TooltipComponent } from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import {
    dayTooltipHtml,
    hourTooltipHtml,
    raceTooltipHtml,
    slotTooltipHtml,
} from "./charts/accuracy-tooltip";
import {
    DEFAULT_PERIOD,
    hasValues,
    markLonePoints,
    periodRows,
    todaySeries,
} from "./charts/hour-of-day";
import { parsed, unwatchSize, watchSize, watchThemeChange } from "./charts/lifecycle";
import { BAND_OPACITY, CHART_FONT, basePalette, token } from "./charts/theme";

echarts.use([LineChart, GridComponent, MarkLineComponent, TooltipComponent, CanvasRenderer]);

/** Forecast page accuracy charts (`data-accuracy-chart`: days, hours, widths, race). Not strips: no time axis, zoom or crosshair. */

/** Chosen through the `accuracy-period` window event the period buttons dispatch. */
let hoursPeriod = DEFAULT_PERIOD;

const DEFAULT_RACE_BLOCK = "morning";

/** Chosen through the `race-block` window event the race's block buttons dispatch. */
let raceBlock = DEFAULT_RACE_BLOCK;

/** Race entrants with a channel colour; any other entrant (the base model) is drawn dashed. */
const RACE_TOKENS = { correction: "--ch1", "light-v6": "--aux", "light-v5": "--ch3" };

/** Day row: `shown`, `base` and `experiment` figures (skill in %, misses and widths in °C) or null, `modelTookOver`, `correctionTookOver`. */

// A calm day can score -1000 % and would flatten the rest; the tooltip prints the real figure.
const SKILL_FLOOR = -100;

/** Hour row: `count`, `inRange`, `error`, `worst`, `bias` in °C (null with none scored), `base` and `experiment` the same for those or null. */

const tick = new Intl.NumberFormat("cs-CZ", {
    maximumFractionDigits: 1,
    signDisplay: "exceptZero",
});

const width = new Intl.NumberFormat("cs-CZ", { maximumFractionDigits: 1 });

const charts = new Map();

/** Last painted payload per chart; an unchanged poll repaints nothing. */
const painted = new Map();

function palette() {
    return { ...basePalette(), temperature: token("--ch1"), experiment: token("--aux") };
}

const pad = (hour) => String(hour).padStart(2, "0");

const TOOLTIP_GAP = 12;
const SCREEN_EDGE = 8;

/** Above the pointer, never past the screen: ECharts' flip and `confine` use the chart's width, which can exceed the screen. */
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
        textStyle: { color: colours.text, fontFamily: CHART_FONT, fontSize: 12 },
        formatter: ([point]) => formatter(point.dataIndex),
    };
}

function zeroLine(colours) {
    return { yAxis: 0, lineStyle: { color: colours.axis, type: "solid", width: 1 } };
}

function experimentLine(rows, value, colours, version) {
    if (rows.every((row) => row.experiment === null)) {
        return [];
    }

    return [
        {
            name: version,
            type: "line",
            connectNulls: false,
            symbol: "circle",
            symbolSize: 6,
            lineStyle: { color: colours.experiment, width: 2 },
            itemStyle: { color: colours.experiment },
            data: rows.map((row) => (row.experiment === null ? null : value(row.experiment))),
        },
    ];
}

function daysOption(rows, canvas, version) {
    const colours = palette();

    return {
        animation: false,
        grid: { left: 44, right: 8, top: 20, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row) => row.date),
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
        tooltip: tooltip(colours, canvas, (index) =>
            dayTooltipHtml(rows[index], version, colours, "skill"),
        ),
        series: [
            {
                name: "base",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 6,
                lineStyle: { color: colours.label, width: 1.5, type: "dashed" },
                itemStyle: { color: colours.label },
                data: rows.map((row) => row.base?.skill ?? null),
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
                data: rows.map((row) => row.shown?.skill ?? null),
            },
            ...experimentLine(rows, (figures) => figures.skill, colours, version),
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
                    xAxis: row.date,
                    label: { formatter: changeLabel(row) },
                    lineStyle: { color: colours.label, type: "dashed", width: 1 },
                })),
        ],
    };
}

function changeLabel(row) {
    return [
        row.modelTookOver === null ? null : "retrained",
        row.correctionTookOver === null ? null : `correction ${row.correctionTookOver}`,
    ]
        .filter(Boolean)
        .join(", ");
}

function hoursOption(payload, canvas, version) {
    const rows = periodRows(payload, hoursPeriod);

    return hoursPeriod === DEFAULT_PERIOD
        ? todayOption(rows, canvas, version)
        : biasOption(rows, canvas, version);
}

function todayOption(slots, canvas, version) {
    const colours = palette();
    const series = todaySeries(slots);
    const band = {
        type: "line",
        stack: "shown-range",
        stackStrategy: "all",
        silent: true,
        showSymbol: false,
        lineStyle: { width: 0 },
        emphasis: { disabled: true },
    };
    const line = (name, data, style) => ({
        name,
        type: "line",
        connectNulls: false,
        symbol: "none",
        itemStyle: { color: style.lineStyle.color },
        data: markLonePoints(data),
        ...style,
    });

    return {
        animation: false,
        grid: { left: 44, right: 8, top: 8, bottom: 22 },
        xAxis: {
            type: "category",
            data: series.clocks,
            boundaryGap: false,
            axisLine: { lineStyle: { color: colours.axis } },
            axisTick: { alignWithLabel: true, lineStyle: { color: colours.axis } },
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
                fontSize: 10,
                hideOverlap: true,
            },
        },
        yAxis: {
            type: "value",
            scale: true,
            splitNumber: 3,
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
                fontSize: 10,
                formatter: (value) => `${width.format(value)} °C`,
            },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        tooltip: tooltip(colours, canvas, (index) =>
            slotTooltipHtml(slots[index], version, colours),
        ),
        series: [
            { ...band, name: "shown range low", data: series.shownLow },
            {
                ...band,
                name: "shown range",
                areaStyle: { color: colours.temperature, opacity: BAND_OPACITY },
                data: series.shownSpread,
            },
            line("base", series.base, {
                lineStyle: { color: colours.label, width: 1.5, type: "dashed" },
            }),
            line("shown", series.shown, { lineStyle: { color: colours.temperature, width: 2 } }),
            ...(hasValues(series.experiment)
                ? [
                      line(version, series.experiment, {
                          lineStyle: { color: colours.experiment, width: 2 },
                      }),
                  ]
                : []),
            line("measured", series.measured, { lineStyle: { color: colours.text, width: 2 } }),
        ],
    };
}

function biasOption(rows, canvas, version) {
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
        tooltip: tooltip(colours, canvas, (index) =>
            hourTooltipHtml(index, rows[index], version, colours),
        ),
        series: [
            {
                name: "base",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 6,
                lineStyle: { color: colours.label, width: 1.5, type: "dashed" },
                itemStyle: { color: colours.label },
                data: rows.map((row) =>
                    row.base === null || row.base.count === 0 ? null : row.base.bias,
                ),
            },
            {
                name: "shown",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 8,
                lineStyle: { color: colours.temperature, width: 2 },
                itemStyle: { color: colours.temperature },
                markLine: {
                    silent: true,
                    symbol: "none",
                    label: { show: false },
                    data: [zeroLine(colours)],
                },
                data: rows.map((row) => row.bias),
            },
            ...experimentLine(rows, (hour) => hour.bias, colours, version),
        ],
    };
}

function widthsOption(rows, canvas, version) {
    const colours = palette();

    return {
        animation: false,
        grid: { left: 44, right: 8, top: 20, bottom: 22 },
        xAxis: {
            type: "category",
            data: rows.map((row) => row.date),
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
        tooltip: tooltip(colours, canvas, (index) =>
            dayTooltipHtml(rows[index], version, colours, "width"),
        ),
        series: [
            {
                name: "base",
                type: "line",
                connectNulls: false,
                symbol: "circle",
                symbolSize: 6,
                lineStyle: { color: colours.label, width: 1.5, type: "dashed" },
                itemStyle: { color: colours.label },
                data: rows.map((row) => row.base?.width ?? null),
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
                data: rows.map((row) => row.shown?.width ?? null),
            },
            ...experimentLine(rows, (figures) => figures.width, colours, version),
        ],
    };
}

function raceEntrants(names, colours) {
    return names.map((name) => ({
        name,
        colour: RACE_TOKENS[name] === undefined ? colours.label : token(RACE_TOKENS[name]),
        dashed: RACE_TOKENS[name] === undefined,
    }));
}

/** Payload: `entrants` in tie order, `days` with `date` and `points` by block, then entrant, in °C. */
function raceOption({ entrants: names, days }, canvas) {
    const colours = palette();
    const entrants = raceEntrants(names, colours);

    return {
        animation: false,
        grid: { left: 44, right: 8, top: 20, bottom: 22 },
        xAxis: {
            type: "category",
            data: days.map((day) => day.date),
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
            splitNumber: 3,
            axisLabel: {
                color: colours.label,
                fontFamily: CHART_FONT,
                fontSize: 10,
                formatter: (value) => `${width.format(value)} °C`,
            },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        tooltip: tooltip(colours, canvas, (index) =>
            raceTooltipHtml(days[index], raceBlock, entrants, colours),
        ),
        series: entrants.map((entrant) => ({
            name: entrant.name,
            type: "line",
            connectNulls: false,
            symbol: "circle",
            symbolSize: 6,
            lineStyle: {
                color: entrant.colour,
                width: entrant.dashed ? 1.5 : 2,
                type: entrant.dashed ? "dashed" : "solid",
            },
            itemStyle: { color: entrant.colour },
            data: days.map((day) => day.points[raceBlock]?.[entrant.name] ?? null),
        })),
    };
}

const OPTIONS = { days: daysOption, hours: hoursOption, widths: widthsOption, race: raceOption };

/** What besides the rows a chart is painted from. */
function choice(key) {
    return { hours: hoursPeriod, race: raceBlock }[key] ?? "";
}

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

        const version = element.dataset.accuracyExperiment;
        const payload = `${choice(key)}${version}${element.dataset.accuracyRows}`;

        if (!force && painted.get(key) === payload) {
            return;
        }

        painted.set(key, payload);
        chart.setOption(OPTIONS[key](parsed(element.dataset.accuracyRows), canvas, version), {
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

document.addEventListener("livewire:navigated", () => {
    hoursPeriod = DEFAULT_PERIOD;
    raceBlock = DEFAULT_RACE_BLOCK;
    mount(true);
});

window.addEventListener("accuracy-period", (event) => {
    hoursPeriod = event.detail.period;
    mount();
});

window.addEventListener("race-block", (event) => {
    raceBlock = event.detail.block;
    mount();
});
