import { describe, expect, it } from "vite-plus/test";
import { hasValues, markLonePoints, periodRows, todaySeries } from "./hour-of-day";

const payload = {
    today: [{ clock: "00:00" }],
    yesterday: [{ bias: 1 }],
    week: [{ bias: 2 }],
    month: [{ bias: 3 }],
};

describe("periodRows", () => {
    it("picks the chosen period's rows", () => {
        expect(periodRows(payload, "week")).toEqual([{ bias: 2 }]);
    });

    it("falls back to today for an unknown period", () => {
        expect(periodRows(payload, "decade")).toEqual([{ clock: "00:00" }]);
    });
});

describe("todaySeries", () => {
    it("splits slots into lines and a band stacked on its low edge", () => {
        const series = todaySeries([
            {
                clock: "07:00",
                measured: 7.8,
                shown: { low: 10.5, mid: 12.3, high: 14.0 },
                base: 8.6,
                experiment: 9.1,
            },
            { clock: "07:10", measured: null, shown: null, base: null, experiment: null },
        ]);

        expect(series).toEqual({
            clocks: ["07:00", "07:10"],
            measured: [7.8, null],
            shown: [12.3, null],
            shownLow: [10.5, null],
            shownSpread: [3.5, null],
            base: [8.6, null],
            experiment: [9.1, null],
        });
    });
});

describe("hasValues", () => {
    it("is false only when every value is missing", () => {
        expect(hasValues([null, null])).toBe(false);
        expect(hasValues([null, 0])).toBe(true);
    });
});

describe("markLonePoints", () => {
    it("marks only values with no neighbour on either side", () => {
        expect(markLonePoints([7.1, null, 7.3, 7.4, null])).toEqual([
            { value: 7.1, symbol: "circle", symbolSize: 5 },
            null,
            7.3,
            7.4,
            null,
        ]);
    });
});
