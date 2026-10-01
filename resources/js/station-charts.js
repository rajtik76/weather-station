// Piecemeal imports: the full ECharts bundle is ~400 kB gzipped.
import * as echarts from "echarts/core";
import { CustomChart, LineChart } from "echarts/charts";
import {
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    MarkLineComponent,
    TooltipComponent,
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import { trackEventHover } from "./charts/events";
import { chartOption, narrowScreen } from "./charts/frame";
import { unwatchSize, watchSize, watchThemeChange, parsed } from "./charts/lifecycle";
import { mountNavigator, resizeNavigator } from "./charts/navigator";
import { typicalStep } from "./charts/rows";
import { charts, state } from "./charts/state";
import { STRIPS } from "./charts/strips";
import { bindZoom, blockWheel, syncCursor } from "./charts/zoom";

echarts.use([
    CustomChart,
    LineChart,
    AxisPointerComponent,
    DataZoomSliderComponent,
    GridComponent,
    MarkLineComponent,
    TooltipComponent,
    CanvasRenderer,
]);

/**
 * One ECharts instance per strip, all built on one frame (chartOption) with
 * one tooltip and one crosshair across them. A zoom is a server round trip:
 * the selected epochs go to Livewire, which re-queries at the bucket width
 * the new span needs.
 *
 * This file is the wiring: it reads the payload into the shared state, mounts
 * the strips and the navigator, and repaints on a poll, a theme switch or a
 * Livewire navigation. What a strip draws lives in charts/strips/.
 */

let mounting = false;

let painted = null;

function paintKey(payload) {
    return (
        payload.dataset.chartRows +
        "\n" +
        payload.dataset.chartEvents +
        "\n" +
        payload.dataset.hiddenChannels +
        "\n" +
        payload.dataset.noiseRows +
        "\n" +
        payload.dataset.lightRows
    );
}

function mount(force = false) {
    if (mounting) {
        return;
    }

    const payload = document.querySelector("[data-chart-rows]");

    if (!payload) {
        return;
    }

    mounting = true;

    try {
        render(payload, force);
    } finally {
        mounting = false;
    }
}

function render(payload, force) {
    state.rows = parsed(payload.dataset.chartRows);

    state.overview = parsed(payload.dataset.navigatorRows);

    state.events = parsed(payload.dataset.chartEvents);

    state.hidden = new Set(parsed(payload.dataset.hiddenChannels));

    state.noiseRows = parsed(payload.dataset.noiseRows);

    state.lightRows = parsed(payload.dataset.lightRows);

    // Heard from the same windows as the noise rows, so it changes only with them:
    // neither paintKey() nor the observer needs it.
    state.rainSlots = parsed(payload.dataset.noiseRain);

    state.stripSteps.clear();
    STRIPS.forEach((strip) =>
        state.stripSteps.set(strip.key, typicalStep(strip.rows(), strip.time)),
    );

    const component = window.Livewire?.find(payload.dataset.chartComponent);

    mountNavigator(payload, component);

    // Most polls change nothing in this window; do not repaint under the pointer.
    if (!force && paintKey(payload) === painted) {
        return;
    }

    painted = paintKey(payload);

    // No mouseout comes for a line that is rebuilt.
    state.hovered = null;

    // The noise strips come and go with the window; a chart whose canvas left the page goes too.
    charts.forEach((chart, key) => {
        if (!document.body.contains(chart.getDom())) {
            disposeStrip(key);
        }
    });

    document.querySelectorAll("[data-strip]").forEach((element) => {
        const strip = STRIPS.find((candidate) => candidate.key === element.dataset.strip);

        if (!strip) {
            return;
        }

        let chart = charts.get(strip.key);

        if (chart && chart.getDom() !== element.querySelector("[data-canvas]")) {
            disposeStrip(strip.key);
            chart = null;
        }

        if (!chart) {
            chart = echarts.init(element.querySelector("[data-canvas]"), null, {
                renderer: "canvas",
            });
            charts.set(strip.key, chart);
            watchSize(strip.key, chart, element, () => strip.afterResize?.(chart));
            blockWheel(element);
            trackEventHover(chart);
            syncCursor(chart);

            strip.mount?.(chart);

            if (component) {
                bindZoom(chart, element, component);
            }
        }

        chart.setOption(chartOption(strip), { notMerge: true });
        strip.afterResize?.(chart);
    });
}

function disposeStrip(key) {
    unwatchSize(key);
    charts.get(key)?.dispose();
    charts.delete(key);
}

/**
 * Livewire rewrites the payload attributes on every poll. The navigator's
 * are watched too: a zoomed window's channel payload never changes while
 * the record keeps growing. Attributes only, never childList: ECharts
 * appends to the body on setOption and the observer would loop.
 */
function watchPayload() {
    new MutationObserver(() => mount()).observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: [
            "data-chart-rows",
            "data-navigator-rows",
            "data-chart-events",
            "data-hidden-channels",
            "data-noise-rows",
            "data-light-rows",
        ],
    });
}

document.addEventListener("DOMContentLoaded", () => {
    mount();
    watchPayload();
    watchThemeChange(() => mount(true));
});

document.addEventListener("livewire:navigated", () => mount(true));
window.addEventListener("resize", resizeNavigator);
// The grid sides are baked into every option; crossing the breakpoint repaints them.
narrowScreen.addEventListener("change", () => mount(true));
