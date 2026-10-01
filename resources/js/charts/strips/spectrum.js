import * as echarts from "echarts/core";
import { formatLevel, formatNumber } from "../format";
import { narrowScreen } from "../frame";
import { NOISE_COLUMN } from "../rows";
import { charts, state } from "../state";
import { isDark, mixColours } from "../theme";
import { RAIN_LANE, rainSeries } from "./rain";

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

/** Light to dark: loud is dark on the light ground; reversed on the dark ground. */
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

/** Null off the waterfall; the axis tooltip knows the time, not the band. */
let spectrumPointer = null;

function trackSpectrumPointer(chart) {
    chart.getZr().on("mousemove", (event) => {
        spectrumPointer = { x: event.offsetX, y: event.offsetY };
    });
    chart.getZr().on("globalout", () => {
        spectrumPointer = null;
    });
}

/** Band under the pointer, or the slot's loudest when the pointer is on another strip. */
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

/** The elements are wire:ignore, so a window without noise must clear them or the old ends stay. */
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

/** Slot width in ms: the bucket a waterfall cell stands for. */
function spectrumSlotWidth() {
    return state.stripSteps.get(SPECTRUM_KEY) || 600000;
}

/** px: a week's slot is under a pixel wide on a phone. */
const COLUMN_MIN_WIDTH = 3;

const columnPlacers = new WeakMap();

/** Crosshair: the slot's whole column, outlined (a line vanished into the ramp, the shadow pointer drew one pixel). Own zlevel so the cells' incremental layer never paints over it. */
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
        // Shifted inside the axis rather than cut, so an end slot stays full width.
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

/** A custom series, not a heatmap: ECharts' heatmap wants a category axis for time. */
function spectrumOption(strip, colours) {
    const range = spectrumRange();
    const width = spectrumSlotWidth();
    // Pinned to the whole window, holes included, or the axis misaligns with the strips above.
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
        grid: state.rainSlots.length > 0 ? { top: 12 + RAIN_LANE } : {},
        yAxis: {
            type: "category",
            data: NOISE_BANDS,
            axisLine: { lineStyle: { color: colours.axis } },
            axisTick: { show: false },
            axisLabel: {
                color: colours.label,
                fontSize: 10,
                interval: 2,
                formatter: (label) => (narrowScreen.matches ? label : `${label} Hz`),
            },
            splitLine: { show: false },
        },
        series: [
            {
                type: "custom",
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
                        // Past hoverLayerThreshold (3000; a day has 26 x 144 cells) a hovered cell would hide the column outline.
                        emphasisDisabled: true,
                    };
                },
            },
            rainSeries(width),
            // A custom series gives the axis tooltip nothing to snap to; an invisible line does.
            {
                type: "line",
                symbol: "none",
                lineStyle: { opacity: 0 },
                data: state.noiseRows.map((row) => [row[NOISE_COLUMN.time], 0]),
            },
        ],
    };
}

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
    /** The outline is placed in pixels, so a resize or repaint must re-place it. */
    afterResize(chart) {
        columnPlacers.get(chart)?.();
    },
};
