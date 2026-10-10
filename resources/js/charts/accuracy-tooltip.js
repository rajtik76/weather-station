import { formatNumber, formatRange, formatSigned, formatWithMinus } from "./format";

/** Forecast page tooltips: a column per forecast, a row per figure. Colours: `temperature`, `experiment`, `label`, `text`. */

const MISSING = "n/a";

const known = (format) => (value) => (value === null ? MISSING : format(value));

const degrees = known((value) => `${formatWithMinus(value, 1)} °C`);
const percent = known((value) => `${formatNumber(value, 0)} %`);
const signedDegrees = known((value) => `${formatSigned(value, 1)} °C`);
const signedPercent = known((value) => `${formatSigned(value, 0)} %`);
const hours = (count) => `${count} h`;
const points = known((value) => `${formatNumber(value, 2)} pts`);

const DAY_FIGURES = [
    { key: "skill", label: "skill", value: (figures) => signedPercent(figures.skill) },
    { key: "error", label: "miss", value: (figures) => degrees(figures.error) },
    { key: "inRange", label: "in range", value: (figures) => percent(figures.inRange) },
    { key: "width", label: "width", value: (figures) => degrees(figures.width) },
    { key: "count", label: "scored", value: (figures) => hours(figures.count) },
];

const HOUR_FIGURES = [
    { key: "bias", label: "bias", value: (figures) => signedDegrees(figures.bias) },
    { key: "error", label: "miss", value: (figures) => degrees(figures.error) },
    { key: "worst", label: "worst", value: (figures) => degrees(figures.worst) },
    { key: "inRange", label: "in range", value: (figures) => percent(figures.inRange) },
    { key: "count", label: "scored", value: (figures) => hours(figures.count) },
];

const CELL = "padding:2px 0 2px 14px;text-align:right;white-space:nowrap";
const LABEL_CELL = "padding:2px 0;text-align:left;white-space:nowrap";

const pad = (hour) => String(hour).padStart(2, "0");

/** The legend's line: solid for a forecast, dashed for the base model. */
function swatch(colour, dashed = false) {
    return (
        `<span style="display:inline-block;width:12px;margin-right:6px;vertical-align:middle;` +
        `border-top:2px ${dashed ? "dashed" : "solid"} ${colour}"></span>`
    );
}

function heading(text, colours) {
    return `<div style="font-weight:500;margin-bottom:6px;color:${colours.text}">${text}</div>`;
}

function notes(lines, colours) {
    return lines
        .map((line) => `<div style="margin-top:6px;color:${colours.label}">${line}</div>`)
        .join("");
}

function forecastColumns(shown, base, experiment, version, colours) {
    return [
        { name: "shown", swatch: swatch(colours.temperature), figures: shown },
        { name: "base", swatch: swatch(colours.label, true), figures: base },
        { name: version, swatch: swatch(colours.experiment), figures: experiment },
    ].filter((column) => column.figures !== null);
}

/** `focus` names the chart's own figure: it leads, in bold. */
function tableHtml(columns, figures, colours, focus = null) {
    const ordered = [
        ...figures.filter((figure) => figure.key === focus),
        ...figures.filter((figure) => figure.key !== focus),
    ];
    const head = columns
        .map((column) => `<th style="${CELL};font-weight:400">${column.swatch}${column.name}</th>`)
        .join("");
    const body = ordered
        .map((figure) => {
            const weight = figure.key === focus ? "font-weight:500;" : "";
            const values = columns
                .map(
                    (column) =>
                        `<td style="${CELL};${weight}">${figure.value(column.figures)}</td>`,
                )
                .join("");

            return (
                `<tr style="color:${colours.text}">` +
                `<td style="${LABEL_CELL};${weight}color:${colours.label}">${figure.label}</td>${values}</tr>`
            );
        })
        .join("");

    return (
        `<table style="border-collapse:collapse;font-variant-numeric:tabular-nums">` +
        `<thead><tr style="color:${colours.label}"><th></th>${head}</tr></thead>` +
        `<tbody>${body}</tbody></table>`
    );
}

function tookOverNotes(row) {
    return [
        row.modelTookOver === null ? null : `model trained ${row.modelTookOver} took over`,
        row.correctionTookOver === null ? null : `correction ${row.correctionTookOver} took over`,
    ].filter(Boolean);
}

/** `focus`: `skill` on the skill chart, `width` on the range width chart. */
export function dayTooltipHtml(row, version, colours, focus) {
    const { shown, base, experiment } = row;

    if (shown === null) {
        return (
            heading(row.date, colours) +
            notes(["no forecast scored", ...tookOverNotes(row)], colours)
        );
    }

    const guess = shown.naive === null ? [] : [`naive guess missed by ${degrees(shown.naive)}`];

    return (
        heading(row.date, colours) +
        tableHtml(
            forecastColumns(shown, base, experiment, version, colours),
            DAY_FIGURES,
            colours,
            focus,
        ) +
        notes([...guess, ...tookOverNotes(row)], colours)
    );
}

export function hourTooltipHtml(hour, row, version, colours) {
    const span = `${pad(hour)}:00-${pad((hour + 1) % 24)}:00`;

    if (row.inRange === null) {
        return heading(span, colours) + notes(["no forecast scored"], colours);
    }

    return (
        heading(span, colours) +
        tableHtml(
            forecastColumns(row, row.base, row.experiment, version, colours),
            HOUR_FIGURES,
            colours,
            "bias",
        ) +
        notes(["bias: reading minus forecast"], colours)
    );
}

export function slotTooltipHtml(slot, version, colours) {
    const lines = [
        {
            swatch: swatch(colours.text),
            label: "measured",
            value: degrees(slot.measured),
            strong: true,
        },
    ];

    if (slot.shown === null) {
        lines.push({ swatch: swatch(colours.temperature), label: "shown", value: MISSING });
    } else {
        lines.push(
            { swatch: swatch(colours.temperature), label: "shown", value: degrees(slot.shown.mid) },
            {
                swatch: swatch("transparent"),
                label: "range",
                value: `${formatRange(slot.shown.low, slot.shown.high, 1)} °C`,
            },
        );
    }

    if (slot.base !== null) {
        lines.push({
            swatch: swatch(colours.label, true),
            label: "base",
            value: degrees(slot.base),
        });
    }

    if (slot.experiment !== null) {
        lines.push({
            swatch: swatch(colours.experiment),
            label: version,
            value: degrees(slot.experiment),
        });
    }

    const rows = lines
        .map(({ swatch: line, label, value, strong }) => {
            const weight = strong ? "font-weight:500;" : "";

            return (
                `<tr><td style="${LABEL_CELL};${weight}color:${colours.label}">${line}${label}</td>` +
                `<td style="${CELL};${weight}color:${colours.text}">${value}</td></tr>`
            );
        })
        .join("");

    return (
        heading(slot.clock, colours) +
        `<table style="border-collapse:collapse;font-variant-numeric:tabular-nums"><tbody>${rows}</tbody></table>`
    );
}

/** A race day's points in `block`, fewest first; `entrants`: `{ name, colour, dashed }` in tie order. */
export function raceTooltipHtml(day, block, entrants, colours) {
    const scored = day.points[block] ?? {};
    const ranked = entrants
        .filter((entrant) => scored[entrant.name] !== undefined)
        .sort((a, b) => scored[a.name] - scored[b.name]);

    if (ranked.length === 0) {
        return heading(day.date, colours) + notes(["no forecast scored"], colours);
    }

    const rows = ranked
        .map((entrant, place) => {
            const weight = place === 0 ? "font-weight:500;" : "";

            return (
                `<tr><td style="${LABEL_CELL};${weight}color:${colours.label}">` +
                `${swatch(entrant.colour, entrant.dashed)}${entrant.name}</td>` +
                `<td style="${CELL};${weight}color:${colours.text}">${points(scored[entrant.name])}</td></tr>`
            );
        })
        .join("");

    return (
        heading(day.date, colours) +
        `<table style="border-collapse:collapse;font-variant-numeric:tabular-nums"><tbody>${rows}</tbody></table>` +
        notes(["lower is better"], colours)
    );
}
