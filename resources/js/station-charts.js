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

/** Wiring: payload into the shared state, strips and navigator mounted. What a strip draws lives in charts/strips/. */

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
        payload.dataset.lightRows +
        "\n" +
        payload.dataset.lightScale
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

    state.lightScale = payload.dataset.lightScale;

    // Changes only with the noise rows: neither paintKey() nor the observer needs it.
    state.rainSlots = parsed(payload.dataset.noiseRain);

    state.stripSteps.clear();
    STRIPS.forEach((strip) =>
        state.stripSteps.set(strip.key, typicalStep(strip.rows(), strip.time)),
    );

    const component = window.Livewire?.find(payload.dataset.chartComponent);

    mountNavigator(payload, component);

    // Do not repaint under the pointer when a poll changed nothing.
    if (!force && paintKey(payload) === painted) {
        return;
    }

    painted = paintKey(payload);

    // No mouseout comes for a line that is rebuilt.
    state.hovered = null;

    // The noise strips come and go with the window.
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

/** Attributes only, never childList: ECharts appends to the body on setOption and the observer would loop. */
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
            "data-light-scale",
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
// Grid sides are baked into every option.
narrowScreen.addEventListener("change", () => mount(true));
