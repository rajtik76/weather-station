import { state } from "../state";
import { ICON_HALF, token } from "../theme";

/** Lucide's cloud-rain and wind. ECharts fits a path's bounding box into the shape, so the two leading moves pin it to the 24x24 grid. */
const RAIN_ICON =
    "M0 0M24 24M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242M16 14v6M8 14v6M12 16v6";
const WIND_ICON =
    "M0 0M24 24M12.8 19.6A2 2 0 1 0 14 16H2M17.5 8a2.5 2.5 0 1 1 2 4H2M9.8 4.4A2 2 0 1 1 11 8H2";

/** Room above the grid for the rain marks, px. */
export const RAIN_LANE = 26;

/** Room below the time axis labels for the wind marks, px. */
export const WIND_LANE = 26;

/** Below the axis line: the time labels sit in between. */
const WIND_OFFSET = 22;

const ICON_GAP = 6;

/** Adjacency by epoch: wall-clock time repeats an hour in autumn and skips one in spring. A stretch is strong if any slot is. */
function stretches(slots, width) {
    const slotSeconds = width / 1000;

    return slots.reduce((joined, [time, epoch, strong = false]) => {
        const last = joined[joined.length - 1];

        if (last && epoch - last.epoch <= slotSeconds * 1.5 && last.strong === strong) {
            last.from = Math.min(last.from, time);
            last.to = Math.max(last.to, time);
            last.epoch = epoch;
        } else {
            joined.push({ from: time, to: time, epoch, strong });
        }

        return joined;
    }, []);
}

/** Stretches closer than one icon's room on screen share their icons, or the icons would draw over each other. */
function iconGroups(extents) {
    const room = 2 * ICON_HALF + ICON_GAP;

    return extents.reduce((groups, extent) => {
        const last = groups[groups.length - 1];

        if (last && extent.left - last.right < room) {
            last.right = Math.max(last.right, extent.right);
            last.strong ||= extent.strong;
        } else {
            groups.push({ ...extent });
        }

        return groups;
    }, []);
}

/**
 * A bar along each stretch and icons spread over groups of them, on a lane outside the grid. One item draws every
 * stretch, so the icons are laid out together; it clips itself to the grid. `top` places the bars and icons.
 */
function markSeries(slots, width, { icon, colour, strongColour, top }) {
    const joined = stretches(slots, width);
    const style = (strong) => ({
        stroke: strong ? strongColour : colour,
        lineWidth: strong ? 2.4 : 1.6,
    });

    return {
        type: "custom",
        clip: false,
        silent: true,
        data: joined.length > 0 ? [[joined[0].from, joined[joined.length - 1].to]] : [],
        encode: { x: [0, 1] },
        renderItem: (params, api) => {
            const grid = params.coordSys;
            const [cellWidth] = api.size([width, 1]);
            const { bar, icons: iconTop } = top(grid);
            const extents = joined
                .map((stretch) => ({
                    left: Math.max(api.coord([stretch.from, 0])[0] - cellWidth / 2, grid.x),
                    right: Math.min(
                        api.coord([stretch.to, 0])[0] + cellWidth / 2,
                        grid.x + grid.width,
                    ),
                    strong: stretch.strong,
                }))
                .filter((extent) => extent.right > extent.left);

            const bars = extents.map((extent) => ({
                type: "rect",
                shape: {
                    x: extent.left,
                    y: bar,
                    width: Math.max(2, extent.right - extent.left),
                    height: extent.strong ? 4 : 3,
                },
                style: { fill: style(extent.strong).stroke },
            }));

            const icons = iconGroups(extents).flatMap((group) => {
                const count = Math.max(
                    1,
                    Math.floor((group.right - group.left) / (2 * ICON_HALF + ICON_GAP)),
                );
                const step = (group.right - group.left) / count;

                return Array.from({ length: count }, (_, index) => {
                    const middle = Math.min(
                        Math.max(group.left + step * (index + 0.5), grid.x + ICON_HALF),
                        grid.x + grid.width - ICON_HALF,
                    );

                    // Placed by the element's own x/y, not the shape's.
                    return {
                        type: "path",
                        x: middle - ICON_HALF,
                        y: iconTop,
                        shape: {
                            pathData: icon,
                            x: 0,
                            y: 0,
                            width: 2 * ICON_HALF,
                            height: 2 * ICON_HALF,
                        },
                        style: {
                            fill: "none",
                            ...style(group.strong),
                            lineCap: "round",
                            lineJoin: "round",
                        },
                    };
                });
            });

            return { type: "group", children: [...bars, ...icons] };
        },
    };
}

export function rainSeries(width) {
    const rain = token("--rain");

    return markSeries(state.rainSlots, width, {
        icon: RAIN_ICON,
        colour: rain,
        strongColour: rain,
        top: (grid) => ({ bar: grid.y - 5, icons: grid.y - RAIN_LANE }),
    });
}

export function windSeries(width) {
    return markSeries(state.windSlots, width, {
        icon: WIND_ICON,
        colour: token("--ink-3"),
        strongColour: token("--ink"),
        top: (grid) => {
            const below = grid.y + grid.height + WIND_OFFSET;

            return { bar: below - 5, icons: below };
        },
    });
}
