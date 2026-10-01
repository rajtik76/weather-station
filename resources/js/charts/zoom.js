import { COLUMN, rowAt } from "./rows";
import { charts } from "./state";

const DRAG_SLOP_PX = 6;

/** ZRender's wheel listener swallows page scroll over a chart; stop it in capture, keep the browser default. */
export function blockWheel(element) {
    element.addEventListener("wheel", (event) => event.stopPropagation(), {
        capture: true,
        passive: true,
    });
}

function epochAt(chart, clientX) {
    const box = chart.getDom().getBoundingClientRect();
    const time = chart.convertFromPixel({ xAxisIndex: 0 }, clientX - box.left);
    const row = rowAt(time);

    return row ? row[COLUMN.epoch] : null;
}

export function bindZoom(chart, element, component) {
    let anchor = null;

    const band = element.querySelector("[data-zoom-band]");

    const paint = (from, to) => {
        if (!band) {
            return;
        }

        band.style.left = `${Math.min(from, to)}px`;
        band.style.width = `${Math.abs(to - from)}px`;
        band.hidden = false;
    };

    // Touch drags too: the strip's touch-action leaves the browser only vertical scroll and pinch, which cancel the pointer.
    element.addEventListener("pointerdown", (event) => {
        if (event.button !== 0 || !event.isPrimary) {
            return;
        }

        anchor = {
            clientX: event.clientX,
            localX: event.clientX - element.getBoundingClientRect().left,
        };
        element.setPointerCapture(event.pointerId);
    });

    element.addEventListener("pointermove", (event) => {
        if (anchor) {
            paint(anchor.localX, event.clientX - element.getBoundingClientRect().left);
        }
    });

    const settle = (event) => {
        if (!anchor) {
            return;
        }

        const travelled = Math.abs(event.clientX - anchor.clientX);
        const from = epochAt(chart, anchor.clientX);
        const to = epochAt(chart, event.clientX);

        anchor = null;

        if (band) {
            band.hidden = true;
        }

        if (travelled >= DRAG_SLOP_PX && from !== null && to !== null && from !== to) {
            component.call("zoomTo", Math.min(from, to), Math.max(from, to));
        }
    };

    element.addEventListener("pointerup", settle);
    element.addEventListener("pointercancel", () => {
        anchor = null;

        if (band) {
            band.hidden = true;
        }
    });

    element.addEventListener("dblclick", () => component.call("resetZoom"));
}

/** Crosshair matched by time: echarts.connect matches by series and data index, which misses when strips draw different series. */
export function syncCursor(chart) {
    const zr = chart.getZr();
    // A collapsed strip has no box to point into.
    const others = () =>
        [...charts.values()].filter((other) => other !== chart && other.getWidth() > 0);

    zr.on("mousemove", (event) => {
        const inGrid = chart.containPixel({ gridIndex: 0 }, [event.offsetX, event.offsetY]);
        const time = inGrid ? chart.convertFromPixel({ xAxisIndex: 0 }, event.offsetX) : null;

        others().forEach((other) => (time === null ? hideCursor(other) : showCursor(other, time)));
    });

    zr.on("globalout", () => others().forEach(hideCursor));
}

/** Half height lands inside every strip's grid, so the tooltip follows x alone. */
function showCursor(chart, time) {
    chart.dispatchAction({
        type: "showTip",
        x: chart.convertToPixel({ xAxisIndex: 0 }, time),
        y: chart.getHeight() / 2,
    });
}

function hideCursor(chart) {
    chart.dispatchAction({ type: "updateAxisPointer", currTrigger: "leave" });
}
