import { state } from "../state";
import { ICON_HALF, isDark } from "../theme";

/**
 * Lucide's cloud-rain, drawn as strokes on the canvas. ECharts fits a path's
 * own bounding box into the shape, so the two leading moves pin that box to
 * Lucide's 24x24 grid and keep the icon's proportions.
 */
const RAIN_ICON =
    "M0 0M24 24M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242M16 14v6M8 14v6M12 16v6";

const RAIN_COLOUR = { light: "#0284c7", dark: "#38bdf8" };

/** The strip above the waterfall the rain markers sit in, so they never cover a cell. */
export const RAIN_LANE = 26;

/** Space between repeated rain icons, px. */
const ICON_GAP = 6;

/**
 * Consecutive rainy slots as one stretch each, so a shower gets one marker,
 * not one per slot. Adjacency by epoch: wall-clock time repeats an hour in
 * autumn and skips one in spring.
 */
function rainStretches(width) {
    const slotSeconds = width / 1000;

    return state.rainSlots.reduce((stretches, [time, epoch]) => {
        const last = stretches[stretches.length - 1];

        if (last && epoch - last.epoch <= slotSeconds * 1.5) {
            last.from = Math.min(last.from, time);
            last.to = Math.max(last.to, time);
            last.epoch = epoch;
        } else {
            stretches.push({ from: time, to: time, epoch });
        }

        return stretches;
    }, []);
}

/**
 * A rain marker in the lane above the waterfall: a bar over the stretch's
 * columns and the icon above it, repeated along the stretch when zoomed in. Drawn outside the grid, so it clips
 * itself: a stretch off the zoomed window draws nothing.
 */

export function rainSeries(width) {
    const rain = RAIN_COLOUR[isDark() ? "dark" : "light"];

    return {
        type: "custom",
        clip: false,
        silent: true,
        data: rainStretches(width).map((stretch) => [stretch.from, stretch.to]),
        encode: { x: [0, 1] },
        renderItem: (params, api) => {
            const grid = params.coordSys;
            const [cellWidth] = api.size([width, 1]);
            const from = api.coord([api.value(0), 0])[0] - cellWidth / 2;
            const to = api.coord([api.value(1), 0])[0] + cellWidth / 2;
            const left = Math.max(from, grid.x);
            const right = Math.min(to, grid.x + grid.width);

            if (right <= left) {
                return null;
            }

            // One icon per slot while they fit side by side, one for the stretch when
            // zoomed out: the lane keeps its height, a long shower just reads longer.
            const slots = Math.round((api.value(1) - api.value(0)) / width) + 1;
            const count = Math.max(
                1,
                Math.min(slots, Math.floor((right - left) / (2 * ICON_HALF + ICON_GAP))),
            );
            const step = (right - left) / count;
            const icons = Array.from({ length: count }, (_, index) => {
                // Kept inside the grid's width at either edge.
                const middle = Math.min(
                    Math.max(left + step * (index + 0.5), grid.x + ICON_HALF),
                    grid.x + grid.width - ICON_HALF,
                );

                // Placed by the element's own x/y, not the shape's: an update on
                // zoom keeps a transform, where legacy positions drifted.
                return {
                    type: "path",
                    x: middle - ICON_HALF,
                    y: grid.y - RAIN_LANE,
                    shape: {
                        pathData: RAIN_ICON,
                        x: 0,
                        y: 0,
                        width: 2 * ICON_HALF,
                        height: 2 * ICON_HALF,
                    },
                    style: {
                        fill: "none",
                        stroke: rain,
                        lineWidth: 1.6,
                        lineCap: "round",
                        lineJoin: "round",
                    },
                };
            });

            return {
                type: "group",
                children: [
                    {
                        type: "rect",
                        shape: {
                            x: left,
                            y: grid.y - 5,
                            width: Math.max(2, right - left),
                            height: 3,
                        },
                        style: { fill: rain },
                    },
                    ...icons,
                ],
            };
        },
    };
}
