const numberFormats = new Map();

/** A number in Czech notation with a fixed count of decimals. */
export function formatNumber(value, decimals) {
    if (!numberFormats.has(decimals)) {
        numberFormats.set(
            decimals,
            new Intl.NumberFormat("cs-CZ", {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }),
        );
    }

    return numberFormats.get(decimals).format(value);
}

export function formatValue(value, channel) {
    return value === null ? "n/a" : `${formatNumber(value, channel.decimals)} ${channel.unit}`;
}

/**
 * The page's one date format, `j.n.Y H:i` as LocalTime::stamp() prints it. Stamps are
 * wall-clock ms tagged UTC (see LocalTime::wallClockMs), so the parts are read as UTC.
 */
export function formatStamp(wallClockMs) {
    const date = new Date(wallClockMs);
    const pad = (part) => String(part).padStart(2, "0");

    return (
        `${date.getUTCDate()}.${date.getUTCMonth() + 1}.${date.getUTCFullYear()} ` +
        `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}`
    );
}

export function formatLevel(value, unit) {
    return value === null ? "n/a" : `${formatNumber(value, 1)} ${unit}`;
}

/** Fewer decimals as the light grows: dusk reads hundredths, noon thousands. */
export function formatLux(value) {
    if (value === null) {
        return "n/a";
    }

    const decimals = value < 10 ? 2 : value < 100 ? 1 : 0;

    return `${formatNumber(value, decimals)} lx`;
}

/** Titles are hand-typed and land in innerHTML. */
export function escapeHtml(text) {
    const node = document.createElement("span");
    node.textContent = text;

    return node.innerHTML;
}
