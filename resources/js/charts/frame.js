import { nearestRow } from "./rows";
import { EVENT_LANE, eventLayer, eventTooltipHtml, marksEvents } from "./events";
import { state } from "./state";
import { CHART_FONT, basePalette } from "./theme";
import { TOOLTIP_GLASS, readingsHtml } from "./tooltip";

/** All numeric to stay language-neutral; ECharts' default prints a bare day number. */
export const TIME_LABELS = {
    year: "{yyyy}",
    month: "{M}/{yyyy}",
    day: "{d}. {M}.",
    hour: "{HH}:{mm}",
    minute: "{HH}:{mm}",
    second: "{HH}:{mm}:{ss}",
    millisecond: "{HH}:{mm}:{ss}",
    none: "{d}. {M}. {yyyy}",
};

/** Shared by every canvas so the stacked time axes line up pixel for pixel; the right margin is a second value axis's width. */
const GRID_SIDES = { left: 64, right: 64 };
const NARROW_GRID_SIDES = { left: 36, right: 30 };

/** Tailwind's `sm` breakpoint. */
export const narrowScreen = window.matchMedia("(max-width: 639px)");

export function gridSides() {
    return narrowScreen.matches ? NARROW_GRID_SIDES : GRID_SIDES;
}

/** Axis pointer pinned to x: on the waterfall ECharts would pick the category band axis. */
export function tooltipFor(strip, colours) {
    return {
        trigger: "axis",
        axisPointer: { axis: "x" },
        appendToBody: true,
        // Keeps the tooltip inside the viewport on a phone.
        confine: true,
        backgroundColor: colours.surface,
        borderColor: colours.border,
        extraCssText: TOOLTIP_GLASS,
        textStyle: { color: colours.label, fontFamily: CHART_FONT, fontSize: 12 },
        formatter: (params) => {
            const point = Array.isArray(params) ? params[0] : params;

            return tooltipHtml(strip, point?.axisValue);
        },
    };
}

function tooltipHtml(strip, time) {
    if (state.hovered) {
        return eventTooltipHtml(strip, state.hovered);
    }

    const row = nearestRow(strip.rows(), time, strip.time);

    return row ? readingsHtml(strip, row) : "";
}

/** The strip's own `grid` and `xAxis` keys refine the shared ones. */
export function chartOption(strip) {
    const colours = basePalette();
    const own = strip.option(strip, colours);

    return {
        animation: false,
        textStyle: { fontFamily: CHART_FONT },
        // Stamps already carry the local offset; UTC keeps the axis on station time for every viewer.
        useUTC: true,
        ...own,
        grid: frameGrid(strip, own.grid),
        // Last, so the lines sit over the waterfall's cells.
        series: [...own.series, ...eventLayer(strip, colours)],
        tooltip: tooltipFor(strip, colours),
        axisPointer: { snap: true },
        xAxis: { ...timeAxis(colours), ...own.xAxis },
    };
}

export function frameGrid(strip, own = {}) {
    const top = own.top ?? 12;

    return {
        ...gridSides(),
        bottom: 28,
        ...own,
        top: marksEvents(strip) ? top + EVENT_LANE : top,
    };
}

export function timeAxis(colours) {
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
