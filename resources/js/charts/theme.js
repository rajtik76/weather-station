/** The page's figures face, for axis labels and tooltips alike. */
export const CHART_FONT = "Red Hat Mono";

export function isDark() {
    return document.documentElement.classList.contains("dark");
}

/**
 * A colour token of the page's `.instrument` shell (resources/css/overview.css),
 * read at paint time so a theme switch repaints in the other theme's values.
 * ECharts paints onto a canvas, so CSS variables do not reach it on their own.
 */
export function token(name) {
    const shell = document.querySelector(".instrument") ?? document.documentElement;

    return getComputedStyle(shell).getPropertyValue(name).trim();
}

/** The six colours every chart shares; a chart adds its own channel colours on top. */
export function basePalette() {
    return {
        axis: token("--line-2"),
        label: token("--ink-3"),
        grid: token("--grid"),
        surface: token("--screen"),
        border: token("--line"),
        text: token("--ink"),
    };
}

/** Opacity of the min-max band behind a line. */
export const BAND_OPACITY = 0.16;

/** Half the size of a marker icon (events, rain), px. */
export const ICON_HALF = 8;

/**
 * The channel per line: CH1 temperature, CH2 humidity, CH3 pressure, CH4
 * noise, AUX light, the dew point on the reference trace.
 */
const CHANNEL_TOKEN = {
    t: "--ch1",
    h: "--ch2",
    d: "--ref",
    p: "--ch3",
    noise: "--ch4",
    light: "--aux",
};

export function colourFor(key) {
    return token(CHANNEL_TOKEN[key]);
}

export function mixColours(from, to, ratio) {
    const channel = (hex, offset) => parseInt(hex.slice(offset, offset + 2), 16);
    const blend = (offset) =>
        Math.round(channel(from, offset) + (channel(to, offset) - channel(from, offset)) * ratio)
            .toString(16)
            .padStart(2, "0");

    return `#${blend(1)}${blend(3)}${blend(5)}`;
}
