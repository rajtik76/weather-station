import { isDark } from "./theme";

/** Each chart's ResizeObserver by key, disconnected where the chart is disposed. Keys are unique across the page's chart files. */
const sizeObservers = new Map();

/**
 * A collapsed panel is hidden, not removed, so its chart shrinks to nothing;
 * the window's resize event never comes when it opens again. The observer
 * resizes the chart, then runs `onResize` for work that follows the size.
 */
export function watchSize(key, chart, element, onResize = () => {}) {
    const observer = new ResizeObserver(() => {
        chart.resize();
        onResize();
    });

    observer.observe(element);
    sizeObservers.set(key, observer);
}

/** A detached element may never get another resize callback; disconnect here, not there. */
export function unwatchSize(key) {
    sizeObservers.get(key)?.disconnect();
    sizeObservers.delete(key);
}

/** Flux toggles `.dark` on the root; a canvas has to be repainted. Fires only when the theme flips. */
export function watchThemeChange(onChange) {
    let dark = isDark();

    new MutationObserver(() => {
        if (dark !== isDark()) {
            dark = isDark();
            onChange();
        }
    }).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ["class"],
    });
}

/** A payload attribute's JSON, the fallback when it is missing or does not parse. */
export function parsed(attribute, fallback = []) {
    try {
        return JSON.parse(attribute ?? JSON.stringify(fallback));
    } catch {
        return fallback;
    }
}
