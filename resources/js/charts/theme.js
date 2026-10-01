export const CHART_FONT = "Red Hat Mono";

export function isDark() {
    return document.documentElement.classList.contains("dark");
}

/** A `.instrument` CSS token, read at paint time: CSS variables do not reach a canvas, and a theme switch repaints. */
export function token(name) {
    const shell = document.querySelector(".instrument") ?? document.documentElement;

    return getComputedStyle(shell).getPropertyValue(name).trim();
}

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

export const BAND_OPACITY = 0.16;

/** Half the size of a marker icon (events, rain), px. */
export const ICON_HALF = 8;

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
