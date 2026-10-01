import { isDark } from "./theme";

/** Keys are unique across the page's chart files. */
const sizeObservers = new Map();

/** A collapsed panel is hidden, not removed, and the window's resize event never comes when it opens again. */
export function watchSize(key, chart, element, onResize = () => {}) {
    const observer = new ResizeObserver(() => {
        chart.resize();
        onResize();
    });

    observer.observe(element);
    sizeObservers.set(key, observer);
}

export function unwatchSize(key) {
    sizeObservers.get(key)?.disconnect();
    sizeObservers.delete(key);
}

/** Flux toggles `.dark` on the root; fires only when the theme flips. */
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

export function parsed(attribute, fallback = []) {
    try {
        return JSON.parse(attribute ?? JSON.stringify(fallback));
    } catch {
        return fallback;
    }
}
