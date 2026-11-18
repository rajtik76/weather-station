import { expect, it } from "vite-plus/test";
import { PlzenHolidays as H } from "./holidays";

it("finds Easter Sunday by the Gregorian computus", () => {
    expect(H.easterSunday(2026)).toEqual({ month: 4, day: 5 });
    expect(H.easterSunday(2027)).toEqual({ month: 3, day: 28 });
});

it("names the holiday on its Prague calendar date", () => {
    expect(H.at(Date.UTC(2026, 11, 24, 12)).key).toBe("christmas-eve");
    expect(H.at(Date.UTC(2027, 2, 29, 12)).key).toBe("easter-monday");
    expect(H.at(Date.UTC(2026, 10, 18, 12))).toBeNull();
});

it("draws no snow at Christmas and nothing on an ordinary day", () => {
    const christmas = H.props(H.at(Date.UTC(2026, 11, 25, 12)));

    expect(Object.values(christmas).join("")).not.toContain("snow");
    expect(H.props(null)).toEqual({ sky: "", mid: "", near: "" });
});

it("keeps flags in the parallax layer of the building they stand on", () => {
    const victory = H.props(H.at(Date.UTC(2026, 4, 8, 12)));

    expect(victory.mid).toContain("hol-wave");
    expect(victory.near).toContain("hol-wave");
    expect(victory.sky).toBe("");
});
