import { escapeHtml, formatStamp } from "./format";
import { EVENT, COLUMN, nearestRow } from "./rows";
import { state } from "./state";
import { ICON_HALF, basePalette } from "./theme";
import { readingsHtml } from "./tooltip";

const EVENT_COLOUR = "#a1a1aa";

export const EVENT_LANE = 26;

/** Lucide's info, pinned to its 24x24 grid like RAIN_ICON. */
const EVENT_ICON = "M0 0M24 24M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20M12 16v-4M12 8h.01";

/** Fits a 320 px phone once tooltip padding and page gutters are in. */
const EVENT_TITLE_WIDTH = 240;

/** Only the strip flagged `events` (temperature) carries the icons; every strip draws the lines. */
export function marksEvents(strip) {
    return strip.events === true && eventsInWindow().length > 0;
}

function stripRowAt(strip, time) {
    const list = strip.rows();
    const row = nearestRow(list, time, strip.time);

    return row !== null &&
        Math.abs(row[strip.time] - time) <= (state.stripSteps.get(strip.key) ?? 0) * 1.5
        ? row
        : null;
}

/** Feeds `state.hovered` to the shared tooltip. */
export function trackEventHover(chart) {
    // mousemove, not mouseover: zrender fires mouseover after the axis pointer, so the first tooltip would miss the title.
    chart.on("mousemove", (params) => {
        if (params.componentType === "markLine") {
            state.hovered = {
                name: params.name,
                time: params.data.xAxis,
                colour: params.data.lineStyle?.color ?? EVENT_COLOUR,
            };
        }
    });

    chart.on("mouseout", (params) => {
        if (params.componentType === "markLine") {
            state.hovered = null;
        }
    });
}

export function eventTooltipHtml(strip, event) {
    const colours = basePalette();
    const row = stripRowAt(strip, event.time);
    const readings = row ? readingsHtml(strip, row) : "";

    // A title is up to 255 characters and ECharts keeps a tooltip on one line, so it wraps here.
    const title =
        `<div style="font-weight:600;font-size:14px;color:${colours.text};` +
        `max-width:${EVENT_TITLE_WIDTH}px;white-space:normal;overflow-wrap:anywhere">` +
        `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;` +
        `background:${event.colour};margin-right:6px"></span>${escapeHtml(event.name)}</div>`;

    if (readings) {
        return (
            title +
            `<div style="margin-top:6px;padding-top:6px;border-top:1px solid ${colours.border}">` +
            `${readings}</div>`
        );
    }

    return title + `<div style="margin-top:2px">${formatStamp(event.time)}</div>`;
}

/** Added by the frame to every strip; no strip draws its own. Lines are unlabelled, the title is in the tooltip. */
export function eventLayer(strip, colours) {
    return [
        // No data of its own: it only carries the lines.
        {
            type: "line",
            data: [],
            markLine: {
                animation: false,
                symbol: "none",
                emphasis: { disabled: true },
                tooltip: { show: false },
                label: { show: false },
                data: eventLines(),
            },
        },
        ...(marksEvents(strip) ? [eventIconSeries(strip, colours)] : []),
    ];
}

/** Events on the strips' rows; an icon off them would stretch the time axis. */
function eventsInWindow() {
    const first = state.rows[0]?.[COLUMN.time];
    const last = state.rows[state.rows.length - 1]?.[COLUMN.time];

    return first === undefined
        ? []
        : state.events.filter((event) => event[EVENT.time] >= first && event[EVENT.time] <= last);
}

/** Outside the grid the axis tooltip does not fire, so the icons carry an item tooltip of their own. */
function eventIconSeries(strip, colours) {
    const icons = eventsInWindow().map((event) => ({
        value: [event[EVENT.time]],
        name: event[EVENT.title],
        colour: eventColour(event[EVENT.colour]),
    }));

    return {
        type: "custom",
        clip: false,
        cursor: "default",
        emphasis: { disabled: true },
        data: icons,
        encode: { x: 0 },
        tooltip: {
            trigger: "item",
            formatter: (params) =>
                eventTooltipHtml(strip, {
                    name: params.name,
                    time: params.value[0],
                    colour: params.data.colour,
                }),
        },
        renderItem: (params, api) => {
            const grid = params.coordSys;
            const x = api.coord([api.value(0), 0])[0];

            if (x < grid.x || x > grid.x + grid.width) {
                return null;
            }

            return {
                type: "path",
                x: x - ICON_HALF,
                y: grid.y - ICON_HALF * 2 - 4,
                shape: {
                    pathData: EVENT_ICON,
                    x: 0,
                    y: 0,
                    width: 2 * ICON_HALF,
                    height: 2 * ICON_HALF,
                },
                style: {
                    // Filled, so the whole disc takes the pointer.
                    fill: colours.surface,
                    stroke: icons[params.dataIndex].colour,
                    lineWidth: 1.6,
                    lineCap: "round",
                    lineJoin: "round",
                },
                emphasisDisabled: true,
            };
        },
    };
}

/** The column takes any string and the value lands in markup: only a real colour gets through. */
function eventColour(value) {
    return typeof value === "string" && CSS.supports("color", value) ? value : EVENT_COLOUR;
}

export function eventLines() {
    return state.events.map((event) => {
        const colour = eventColour(event[EVENT.colour]);

        return {
            name: event[EVENT.title],
            xAxis: event[EVENT.time],
            // Explicit pattern: "dashed" scales with the width.
            lineStyle: { color: colour, type: [4, 3], width: 2, opacity: 0.9 },
            label: { color: colour },
        };
    });
}
