import { afterEach, expect, it, vi } from "vite-plus/test";
import { state } from "../state";
import { windSeries } from "./marks";

vi.mock("../theme", () => ({ ICON_HALF: 8, token: () => "#555555" }));

const SLOT = 600000;

/** One px per ten-minute slot on a 1000 px grid: a week squeezes 144 slots a day into ~143 px. */
function draw(series) {
    const api = { coord: ([time]) => [time / SLOT, 0], size: () => [1, 0] };

    return (
        series.renderItem({ coordSys: { x: 0, y: 0, width: 1000, height: 100 } }, api)?.children ??
        []
    );
}

afterEach(() => {
    state.windSlots = [];
});

it("keeps wind icons apart when gusty stretches sit a few pixels apart", () => {
    state.windSlots = [100, 103, 104, 107, 112, 300].map((slot, index) => [
        slot * SLOT,
        slot * 600,
        index === 2,
    ]);

    const children = draw(windSeries(SLOT));
    const icons = children.filter((child) => child.type === "path").map((icon) => icon.x);

    expect(icons).toHaveLength(2);
    expect(icons[1] - icons[0]).toBeGreaterThanOrEqual(22);
    expect(children.filter((child) => child.type === "rect")).toHaveLength(6);
});

it("draws a group in the strong style when any of its stretches was strong", () => {
    state.windSlots = [
        [100 * SLOT, 100 * 600, false],
        [103 * SLOT, 103 * 600, true],
    ];

    const [icon] = draw(windSeries(SLOT)).filter((child) => child.type === "path");

    expect(icon.style.lineWidth).toBe(2.4);
});

it("draws nothing without wind", () => {
    expect(windSeries(SLOT).data).toEqual([]);
});
