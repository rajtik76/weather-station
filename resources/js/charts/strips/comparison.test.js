import { afterEach, expect, it, vi } from "vite-plus/test";
import { state } from "../state";
import { comparisonStrip } from "./comparison";
import { temperatureStrip } from "./weather";

vi.mock("../theme", () => ({
    BAND_OPACITY: 0.16,
    colourFor: () => "#555555",
    mixColours: () => "#555555",
}));

afterEach(() => {
    state.rows = [];
    state.comparisonRows = [];
    state.correctedByTime.clear();
    state.hidden.clear();
});

it("keeps missing estimates and references as gaps in the comparison", () => {
    state.comparisonRows = [
        [1000, 12, 11, 10],
        [2000, 13, null, null],
    ];

    const option = comparisonStrip.option(comparisonStrip, { grid: "#eeeeee" });

    expect(option.series.map((series) => series.data)).toEqual([
        [
            [1000, 12],
            [2000, 13],
        ],
        [
            [1000, 11],
            [2000, null],
        ],
        [
            [1000, 10],
            [2000, null],
        ],
    ]);
    expect(comparisonStrip.lines(state.comparisonRows[1]).map((line) => line.value)).toEqual([
        "13,00 °C",
        "n/a",
        "n/a",
    ]);
});

it("pairs the weather correction by slot time and preserves an unavailable slot", () => {
    state.rows = [
        [1000, 12, 60, 1000, 4, 1, 12, 12, 60, 60, 1000, 1000],
        [2000, 13, 60, 1000, 4, 2, 13, 13, 60, 60, 1000, 1000],
    ];
    state.correctedByTime = new Map([[1000, 11]]);

    const option = temperatureStrip.option(temperatureStrip, { grid: "#eeeeee" });

    expect(option.series.find((series) => series.name === "Shade estimate").data).toEqual([
        [1000, 11],
        [2000, null],
    ]);
    expect(
        temperatureStrip
            .lines(state.rows[1], temperatureStrip)
            .find((line) => line.label === "Shade estimate").value,
    ).toBe("n/a");
});
