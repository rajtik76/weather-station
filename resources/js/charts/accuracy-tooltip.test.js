import { describe, expect, it } from "vite-plus/test";
import { dayTooltipHtml, hourTooltipHtml, slotTooltipHtml } from "./accuracy-tooltip";

const colours = { temperature: "#d00", experiment: "#0a0", label: "#888", text: "#111" };

const figures = (overrides = {}) => ({
    count: 24,
    skill: 32,
    error: 0.84,
    naive: 1.5,
    inRange: 82,
    width: 2.1,
    ...overrides,
});

const day = (overrides = {}) => ({
    date: "6.10.2026",
    shown: figures(),
    base: figures({ skill: -12, error: 1.04 }),
    experiment: null,
    modelTookOver: null,
    correctionTookOver: null,
    ...overrides,
});

const cells = (html) => [...html.matchAll(/<t[dh][^>]*>(.*?)<\/t[dh]>/g)].map((match) => match[1]);

const rowLabels = (html) =>
    [...html.matchAll(/<tr[^>]*><td[^>]*>(.*?)<\/td>/g)].map((match) => match[1]);

describe("dayTooltipHtml", () => {
    it("lays each forecast's figures out in its own column", () => {
        const html = dayTooltipHtml(
            day({ experiment: figures({ error: null }) }),
            "light-v5",
            colours,
            "skill",
        );

        expect(cells(html)).toEqual(
            expect.arrayContaining([
                expect.stringContaining("shown"),
                expect.stringContaining("base"),
                expect.stringContaining("light-v5"),
                "skill",
                "+32 %",
                "−12 %",
                "miss",
                "0,8 °C",
                "1,0 °C",
                "n/a",
            ]),
        );
        expect(html).toContain("naive guess missed by 1,5 °C");
    });

    it("leads with the chart's own figure", () => {
        expect(rowLabels(dayTooltipHtml(day(), "", colours, "width"))).toEqual([
            "width",
            "skill",
            "miss",
            "in range",
            "scored",
        ]);
    });

    it("leaves out a forecast that was not scored", () => {
        const html = dayTooltipHtml(day({ base: null }), "light-v5", colours, "skill");

        expect(html).not.toContain(">base<");
        expect(html).not.toContain("light-v5");
    });

    it("says so when nothing was scored", () => {
        const html = dayTooltipHtml(
            day({ shown: null, modelTookOver: "2026-09-20" }),
            "",
            colours,
            "skill",
        );

        expect(html).not.toContain("<table");
        expect(html).toContain("no forecast scored");
        expect(html).toContain("model trained 2026-09-20 took over");
    });
});

describe("hourTooltipHtml", () => {
    it("prints the bias signed beside the base model's", () => {
        const base = {
            count: 30,
            inRange: 70,
            error: 1.2,
            worst: 3.4,
            bias: -0.04,
            base: null,
            experiment: null,
        };
        const html = hourTooltipHtml(
            23,
            { count: 30, inRange: 80, error: 0.9, worst: 2.8, bias: 0.8, base, experiment: null },
            "",
            colours,
        );

        expect(html).toContain("23:00-00:00");
        expect(cells(html)).toEqual(expect.arrayContaining(["bias", "+0,8 °C", "−0,0 °C"]));
    });
});

describe("slotTooltipHtml", () => {
    it("joins a range below zero with 'to'", () => {
        const html = slotTooltipHtml(
            {
                clock: "06:20",
                measured: -1.2,
                shown: { low: -3, mid: -1.5, high: 0.4 },
                base: null,
                experiment: null,
            },
            "",
            colours,
        );

        expect(cells(html)).toEqual(expect.arrayContaining(["−1,2 °C", "−3,0 to 0,4 °C"]));
        expect(html).not.toContain("base");
    });
});
