import * as echarts from "echarts/core";
import { formatLevel, formatNumber } from "../format";
import { narrowScreen } from "../frame";
import { NOISE_COLUMN } from "../rows";
import { charts, state } from "../state";
import { isDark, mixColours } from "../theme";
import { RAIN_LANE, rainSeries } from "./rain";

/** The strip key: the tooltip and the column outline look the strip up by it. */
const SPECTRUM_KEY = "spectrum";

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

/** The window's quietest and loudest band, so the ramp spends its steps on what is there. */
function spectrumRange() {
    const values = state.noiseRows.flatMap((row) =>
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
    const chart = charts.get(SPECTRUM_KEY);
    const levels = row.slice(NOISE_COLUMN.band);

    if (chart && spectrumPointer !== null) {
        const band = Math.round(chart.convertFromPixel({ yAxisIndex: 0 }, spectrumPointer.y));

        if (band >= 0 && band < NOISE_BANDS.length) {
            return band;
        }
    }

    return levels.indexOf(Math.max(...levels.filter((value) => value !== null)));
}

function spectrumLines(row) {
    if (row[NOISE_COLUMN.laeq] === null) {
        return [];
    }

    const band = spectrumBandAt(row);

    return [
        {
            label: `${NOISE_BANDS[band]} Hz`,
            value: formatLevel(row[NOISE_COLUMN.band + band], "dB"),
        },
    ];
}

/**
 * Paints the scale beside the strip's label with the ramp and its ends. A
 * window without noise clears it: the elements are wire:ignore, so the
 * previous window's ends would otherwise stay beside an empty strip.
 */
function paintSpectrumScale(range) {
    const scale = document.querySelector("[data-spectrum-scale]");
    const low = document.querySelector("[data-spectrum-low]");
    const high = document.querySelector("[data-spectrum-high]");

    if (!scale || !low || !high) {
        return;
    }

    if (!range) {
        scale.style.background = "";
        low.textContent = "";
        high.textContent = "";

        return;
    }

    scale.style.background = `linear-gradient(to right, ${spectrumRamp().join(", ")})`;
    low.textContent = formatNumber(range.low, 0);
    high.textContent = formatNumber(range.high, 0);
}

/** The noise rows' own slot width, ms: it is the bucket a waterfall cell stands for. */
function spectrumSlotWidth() {
    return state.stripSteps.get(SPECTRUM_KEY) || 600000;
}

/** Narrowest column outline, px: a week's slot is under a pixel wide on a phone. */
const COLUMN_MIN_WIDTH = 3;

/** Each mounted waterfall's way of putting its column outline back where it was. */
const columnPlacers = new WeakMap();

/**
 * The waterfall's crosshair is the slot's whole column, outlined. A line
 * vanished into the blue ramp, and ECharts' own shadow pointer finds no band
 * width on a time axis, so it drew a single pixel. It follows the axis
 * pointer, so a crosshair synced from another strip outlines it too.
 *
 * A light line in a dark one: it reads on either end of the ramp without
 * tinting the cells it frames. On its own zlevel, so the cells' incremental
 * layer never paints over it and a pointer move repaints the outline alone.
 */
function trackSpectrumColumn(chart) {
    const outline = { fill: "none", lineJoin: "miter" };
    const column = new echarts.graphic.Group({ silent: true, ignore: true });
    const outer = new echarts.graphic.Rect({
        zlevel: 1,
        style: { ...outline, stroke: "#000000a0", lineWidth: 3 },
    });
    const inner = new echarts.graphic.Rect({
        zlevel: 1,
        z2: 1,
        style: { ...outline, stroke: "#ffffff", lineWidth: 1 },
    });

    column.add(outer);
    column.add(inner);
    chart.getZr().add(column);

    let shownTime;

    const place = (time) => {
        shownTime = time;

        const first = state.noiseRows[0]?.[NOISE_COLUMN.time];
        const last = state.noiseRows[state.noiseRows.length - 1]?.[NOISE_COLUMN.time];

        if (time === undefined || first === undefined) {
            column.hide();

            return;
        }

        const half = spectrumSlotWidth() / 2;
        const toX = (value) => chart.convertToPixel({ xAxisIndex: 0 }, value);
        // Band indices run bottom to top; the cells stand half a band past each end.
        const bottom = chart.convertToPixel({ yAxisIndex: 0 }, 0);
        const top = chart.convertToPixel({ yAxisIndex: 0 }, NOISE_BANDS.length - 1);
        const halfBand = (bottom - top) / (NOISE_BANDS.length - 1) / 2;
        const slot = Math.max(COLUMN_MIN_WIDTH, toX(time + half) - toX(time - half));
        // Kept inside the axis, where the cells are clipped, and shifted in
        // rather than cut at either edge, so an end slot stays full width.
        const axisLeft = toX(first);
        const axisRight = toX(last);
        const left = Math.max(axisLeft, Math.min(toX(time) - slot / 2, axisRight - slot));
        const shape = {
            x: left,
            y: top - halfBand,
            width: Math.min(axisRight, left + slot) - left,
            height: bottom - top + 2 * halfBand,
        };

        outer.setShape(shape);
        inner.setShape(shape);
        column.show();
    };

    chart.on("updateAxisPointer", (event) =>
        place(event.axesInfo?.find((axis) => axis.axisDim === "x")?.value),
    );
    columnPlacers.set(chart, () => place(shownTime));
}

/**
 * The waterfall: one cell per slot and band on the same time axis as the
 * strips above, so zoom and crosshair carry over. A custom series rather
 * than a heatmap - ECharts' heatmap wants a category axis for time.
 */
function spectrumOption(strip, colours) {
    const range = spectrumRange();
    const width = spectrumSlotWidth();
    // Pinned to the whole window, holes included: the cells alone would
    // stretch the axis over the stretch that has noise and misalign it
    // with every strip above.
    const first = state.noiseRows[0]?.[NOISE_COLUMN.time];
    const last = state.noiseRows[state.noiseRows.length - 1]?.[NOISE_COLUMN.time];

    paintSpectrumScale(range);

    const cells = range
        ? state.noiseRows.flatMap((row) =>
              NOISE_BANDS.map((_, band) => [
                  row[NOISE_COLUMN.time],
                  band,
                  row[NOISE_COLUMN.band + band],
              ]).filter((cell) => cell[2] !== null),
          )
        : [];

    return {
        xAxis: {
            min: first,
            max: last,
            // No line: trackSpectrumColumn() lights the column.
            axisPointer: { lineStyle: { opacity: 0 } },
        },
        // Room above the cells for the rain markers, only when there is rain to mark.
        grid: state.rainSlots.length > 0 ? { top: 12 + RAIN_LANE } : {},
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
                // The bare band on a phone, to fit the narrow side.
                formatter: (label) => (narrowScreen.matches ? label : `${label} Hz`),
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
                        // Past ECharts' hoverLayerThreshold (3000 elements, a day already
                        // has 26 x 144 cells) highlighted cells move to a layer above
                        // every zlevel and hide the column outline. The series-level
                        // emphasis.disabled does not reach a custom series' elements.
                        emphasisDisabled: true,
                    };
                },
            },
            rainSeries(width),
            // A custom series gives the axis tooltip nothing to snap to, so
            // it would not show: an invisible line through every slot does.
            {
                type: "line",
                // Not even the dot a hovered point gets: the column is the crosshair.
                symbol: "none",
                lineStyle: { opacity: 0 },
                data: state.noiseRows.map((row) => [row[NOISE_COLUMN.time], 0]),
            },
        ],
    };
}

/** The waterfall: its tooltip and column track the pointer, and its outline follows a resize or repaint. */
export const spectrumStrip = {
    key: SPECTRUM_KEY,
    option: spectrumOption,
    lines: spectrumLines,
    rows: () => state.noiseRows,
    time: NOISE_COLUMN.time,
    mount(chart) {
        trackSpectrumPointer(chart);
        trackSpectrumColumn(chart);
    },
    /** The outline is placed in pixels on a pointer move; a resize or repaint moves the cells under it. */
    afterResize(chart) {
        columnPlacers.get(chart)?.();
    },
};
