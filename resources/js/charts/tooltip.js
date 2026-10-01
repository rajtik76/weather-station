import { formatStamp } from "./format";
import { basePalette } from "./theme";

/** Frosted like the tiles the charts sit on; ECharts takes the rest as inline CSS. */
export const TOOLTIP_GLASS =
    "backdrop-filter: blur(12px); border-radius: 12px; box-shadow: 0 8px 24px rgb(15 28 46 / 0.14);";

/** The slot's stamp over the strip's own lines; nothing when the strip has nothing to say for it. */
export function readingsHtml(strip, row) {
    const lines = strip.lines(row, strip);

    if (lines.length === 0) {
        return "";
    }

    const colours = basePalette();

    return (
        `<div style="font-weight:500;margin-bottom:4px;color:${colours.text}">` +
        `${formatStamp(row[strip.time])}</div>` +
        lines.map((line) => tooltipLine(line, colours)).join("")
    );
}

/** `colour` puts a dot before the label, `detail` a smaller line under the value. */
function tooltipLine({ label, value, colour = "", strong = false, detail = "" }, colours) {
    const dot = colour
        ? `<span style="display:inline-block;width:8px;height:8px;border-radius:9999px;` +
          `background:${colour};margin-right:6px"></span>`
        : "";
    const under = detail
        ? `<div style="display:flex;gap:12px;font-size:11px;opacity:0.8">` +
          `<span style="margin-left:auto;font-variant-numeric:tabular-nums">${detail}</span></div>`
        : "";

    return (
        `<div style="display:flex;align-items:center;gap:12px">` +
        `<span>${dot}${label}</span>` +
        `<span style="margin-left:auto;font-variant-numeric:tabular-nums;color:${colours.text};` +
        `${strong ? "font-weight:500" : ""}">${value}</span></div>` +
        under
    );
}
