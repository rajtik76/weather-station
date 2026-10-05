import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import { GridComponent } from "echarts/components";
import { SVGRenderer } from "echarts/renderers";
import { afterEach, describe, expect, it, vi } from "vite-plus/test";
import { LIGHT_FLOOR, LIGHT_SCALE } from "../light-scale";
import { state } from "../state";
import { lightStrip } from "./light";

vi.mock("../theme", () => ({ BAND_OPACITY: 0.16, colourFor: () => "#f59e0b" }));

echarts.use([LineChart, GridComponent, SVGRenderer]);

const HOUR_MS = 3_600_000;
const MEAN_SERIES = 2;

let chart = null;

function lightRow(hour, lux) {
    return [hour * HOUR_MS, hour * 3600, lux, lux, lux];
}

/** Pixel y of each plotted mean. */
function drawn(scale, luxValues) {
    state.lightScale = scale;
    state.lightRows = luxValues.map((lux, hour) => lightRow(hour, lux));

    const option = lightStrip.option(lightStrip, { grid: "#e5e7eb" });

    chart = echarts.init(null, null, { renderer: "svg", ssr: true, width: 600, height: 400 });
    chart.setOption({
        animation: false,
        grid: { top: 10, bottom: 10, left: 10, right: 10 },
        xAxis: { type: "time" },
        ...option,
    });

    return option.series[MEAN_SERIES].data.map(
        (point) => chart.convertToPixel({ seriesIndex: MEAN_SERIES }, point)[1],
    );
}

function gridBottom() {
    const rect = chart.getModel().getComponent("grid").coordinateSystem.getRect();

    return rect.y + rect.height;
}

function gapRatio([low, middle, high]) {
    return (low - middle) / (middle - high);
}

afterEach(() => {
    chart?.dispose();
    chart = null;
    state.lightScale = LIGHT_SCALE.log;
    state.lightRows = [];
});

describe("light strip", () => {
    it("draws lux linearly on the linear scale", () => {
        expect(gapRatio(drawn(LIGHT_SCALE.linear, [10, 100, 1000]))).toBeCloseTo(0.1, 5);
    });

    it("draws lux logarithmically on the log scale", () => {
        expect(gapRatio(drawn(LIGHT_SCALE.log, [10, 100, 1000]))).toBeCloseTo(1, 5);
    });

    it("draws a dark night at zero on the bottom of the linear scale", () => {
        const [night] = drawn(LIGHT_SCALE.linear, [0, 1000]);

        expect(chart.getOption().series[MEAN_SERIES].data[0][1]).toBe(0);
        expect(night).toBeCloseTo(gridBottom(), 5);
    });

    it("draws a dark night on the floor at the bottom of the log scale", () => {
        const [night] = drawn(LIGHT_SCALE.log, [0, 1000]);

        expect(chart.getOption().series[MEAN_SERIES].data[0][1]).toBe(LIGHT_FLOOR);
        expect(night).toBeCloseTo(gridBottom(), 5);
    });
});
