import * as echarts from "echarts/core";
import { eventLines } from "./events";
import { TIME_LABELS, gridSides } from "./frame";
import { COLUMN, epochFromWallMs } from "./rows";
import { state } from "./state";
import { CHART_FONT, basePalette, colourFor, isDark } from "./theme";
import { blockWheel } from "./zoom";

let overviewChart = null;

/** True while we position the slider ourselves, so its datazoom echo is not taken for a drag. */
let settingWindow = false;

/** Epochs; a slider that lands back on it needs no query. */
let applied = { from: null, to: null };

function navigatorOption(from, to) {
    const colours = basePalette();

    return {
        animation: false,
        textStyle: { fontFamily: CHART_FONT },
        useUTC: true,
        grid: { ...gridSides(), top: 4, height: 44 },
        // The slider narrows the axis it drives, so that one is hidden and a second, pinned to the record's ends, carries labels and event lines.
        xAxis: [
            { type: "time", show: false },
            {
                type: "time",
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
                ...gridSides(),
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
                data: state.overview.map((row) => [row[COLUMN.time], row[COLUMN.t]]),
            },
            {
                type: "line",
                xAxisIndex: 1,
                showSymbol: false,
                lineStyle: { width: 0 },
                data: state.overview.map((row) => [row[COLUMN.time], row[COLUMN.t]]),
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

        pending = setTimeout(() => {
            const zoom = overviewChart.getOption().dataZoom?.[0];

            if (!zoom) {
                return;
            }

            const from = epochFromWallMs(state.overview, zoom.startValue);
            const to = epochFromWallMs(state.overview, zoom.endValue);

            if (from === null || to === null || from >= to) {
                return;
            }

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

export function mountNavigator(payload, component) {
    const element = document.querySelector("[data-navigator]");

    if (!element || state.overview.length === 0) {
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
        from: epochFromWallMs(state.overview, from),
        to: epochFromWallMs(state.overview, to),
    };

    settingWindow = true;
    overviewChart.setOption(navigatorOption(from, to), { notMerge: true });
    // The event can arrive after setOption returns.
    setTimeout(() => {
        settingWindow = false;
    }, 0);
}

export function resizeNavigator() {
    overviewChart?.resize();
}
