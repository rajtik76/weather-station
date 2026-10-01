import { formatLevel } from "../format";
import { NOISE_COLUMN } from "../rows";
import { state } from "../state";
import { BAND_OPACITY, colourFor } from "../theme";

function noiseColour() {
    return colourFor("noise");
}

function noiseLines(row) {
    return [
        { label: "LAeq", value: formatLevel(row[NOISE_COLUMN.laeq], "dB(A)"), strong: true },
        { label: "LA10", value: formatLevel(row[NOISE_COLUMN.la10], "dB(A)") },
        { label: "LA90", value: formatLevel(row[NOISE_COLUMN.la90], "dB(A)") },
        { label: "LAmax", value: formatLevel(row[NOISE_COLUMN.lamax], "dB(A)") },
    ];
}

/** LAeq over the LA90 to LA10 band - the level most of the window sat in - with LAmax dotted above. */
function noiseOption(strip, colours) {
    const colour = noiseColour();
    const time = (row) => row[NOISE_COLUMN.time];
    const spread = (row) =>
        row[NOISE_COLUMN.la90] === null || row[NOISE_COLUMN.la10] === null
            ? null
            : row[NOISE_COLUMN.la10] - row[NOISE_COLUMN.la90];

    return {
        yAxis: {
            type: "value",
            scale: true,
            axisLabel: { fontSize: 10, color: colour },
            splitLine: { lineStyle: { color: colours.grid } },
        },
        series: [
            {
                type: "line",
                stack: "noise-band",
                stackStrategy: "all",
                silent: true,
                showSymbol: false,
                lineStyle: { opacity: 0 },
                data: state.noiseRows.map((row) => [time(row), row[NOISE_COLUMN.la90]]),
            },
            {
                type: "line",
                stack: "noise-band",
                stackStrategy: "all",
                silent: true,
                showSymbol: false,
                lineStyle: { opacity: 0 },
                areaStyle: { color: colour, opacity: BAND_OPACITY },
                data: state.noiseRows.map((row) => [time(row), spread(row)]),
            },
            {
                type: "line",
                name: "LAmax",
                showSymbol: false,
                lineStyle: { width: 1, color: colour, opacity: 0.6, type: [2, 3] },
                itemStyle: { color: colour },
                data: state.noiseRows.map((row) => [time(row), row[NOISE_COLUMN.lamax]]),
            },
            {
                type: "line",
                name: "LAeq",
                showSymbol: false,
                lineStyle: { width: 1.5, color: colour },
                itemStyle: { color: colour },
                data: state.noiseRows.map((row) => [time(row), row[NOISE_COLUMN.laeq]]),
            },
        ],
    };
}

/** The noise strip: LAeq over the LA90 to LA10 band, LAmax dotted. */
export const noiseStrip = {
    key: "noise",
    option: noiseOption,
    lines: noiseLines,
    rows: () => state.noiseRows,
    time: NOISE_COLUMN.time,
};
