/**
 * Drag-to-select over a Flux chart.
 *
 * Flux charts ship hover scrubbing but no range selection, so this adds a
 * brush: press, drag, release marks a span of readings and reports how many
 * fell inside it along with their min, max and mean.
 */

/** A drag shorter than this counts as a click, which clears the selection. */
const CLICK_SLOP_PX = 4;

const numberFormats = new Map();

function formatNumber(value, decimals) {
    if (!numberFormats.has(decimals)) {
        numberFormats.set(
            decimals,
            new Intl.NumberFormat('cs-CZ', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }),
        );
    }

    return numberFormats.get(decimals).format(value);
}

const stampFormat = new Intl.DateTimeFormat('cs-CZ', {
    day: 'numeric',
    month: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'UTC',
});

function show(el, visible) {
    if (el) {
        el.style.display = visible ? '' : 'none';
    }
}

function createChartBrush(root) {
    const chart = root.querySelector('ui-chart');
    const viewport = root.querySelector('[data-brush-viewport]');
    const band = root.querySelector('[data-brush-band]');
    const readout = root.querySelector('[data-brush-readout]');
    const hint = root.querySelector('[data-brush-hint]');

    if (!chart || !viewport || !band || !readout) {
        return;
    }

    const field = root.dataset.brushField;
    const decimals = Number.parseInt(root.dataset.brushDecimals ?? '1', 10);

    let data = [];

    try {
        data = JSON.parse(chart.getAttribute('value') ?? '[]');
    } catch {
        return;
    }

    if (data.length < 2) {
        return;
    }

    const slots = {
        range: readout.querySelector('[data-brush-range]'),
        count: readout.querySelector('[data-brush-count]'),
        min: readout.querySelector('[data-brush-min]'),
        max: readout.querySelector('[data-brush-max]'),
        avg: readout.querySelector('[data-brush-avg]'),
    };

    let selection = null;
    let anchor = null;

    /**
     * Plot geometry read off the rendered line, whose first and last points
     * mark the exact span of the data. The declared gutter is only a reserve:
     * Flux widens the left inset to fit the y-axis labels.
     */
    function plot() {
        const svg = chart.querySelector('svg');

        if (!svg) {
            return null;
        }

        const line = [...svg.querySelectorAll('path[d]')]
            .sort((a, b) => b.getAttribute('d').length - a.getAttribute('d').length)[0];

        if (!line) {
            return null;
        }

        const lineRect = line.getBoundingClientRect();
        const viewportRect = viewport.getBoundingClientRect();

        return {
            pageLeft: lineRect.left,
            localLeft: lineRect.left - viewportRect.left,
            width: lineRect.width,
        };
    }

    function indexAt(clientX) {
        const area = plot();

        if (!area || area.width <= 0) {
            return 0;
        }

        const ratio = (clientX - area.pageLeft) / area.width;

        return Math.min(data.length - 1, Math.max(0, Math.round(ratio * (data.length - 1))));
    }

    function offsetOf(index) {
        const area = plot();

        return area ? area.localLeft + (index / (data.length - 1)) * area.width : 0;
    }

    function render() {
        if (!selection) {
            show(band, false);
            show(readout, false);
            show(hint, true);

            return;
        }

        const from = offsetOf(selection.from);
        const to = offsetOf(selection.to);

        band.style.left = `${Math.min(from, to)}px`;
        band.style.width = `${Math.max(Math.abs(to - from), 1)}px`;

        show(band, true);
        show(readout, true);
        show(hint, false);

        const picked = data.slice(selection.from, selection.to + 1);
        const values = picked.map((datum) => Number(datum[field]));
        const total = values.reduce((sum, value) => sum + value, 0);

        slots.range.textContent = `${stampFormat.format(new Date(picked[0].d))} → ${stampFormat.format(new Date(picked[picked.length - 1].d))}`;
        slots.count.textContent = formatNumber(picked.length, 0);
        slots.min.textContent = formatNumber(Math.min(...values), decimals);
        slots.max.textContent = formatNumber(Math.max(...values), decimals);
        slots.avg.textContent = formatNumber(total / values.length, decimals);
    }

    function clear() {
        selection = null;
        render();
    }

    viewport.addEventListener('pointerdown', (event) => {
        // Touch drags belong to the page scroller, not the brush.
        if (event.button !== 0 || event.pointerType === 'touch') {
            return;
        }

        viewport.setPointerCapture(event.pointerId);
        anchor = { index: indexAt(event.clientX), clientX: event.clientX };
        selection = null;
        render();
    });

    viewport.addEventListener('pointermove', (event) => {
        if (!anchor) {
            return;
        }

        const index = indexAt(event.clientX);

        if (index === anchor.index) {
            return;
        }

        selection = {
            from: Math.min(anchor.index, index),
            to: Math.max(anchor.index, index),
        };

        render();
    });

    function finish(event) {
        if (!anchor) {
            return;
        }

        if (Math.abs(event.clientX - anchor.clientX) < CLICK_SLOP_PX) {
            clear();
        }

        anchor = null;
    }

    viewport.addEventListener('pointerup', finish);
    viewport.addEventListener('pointercancel', finish);

    root.querySelector('[data-brush-clear]')?.addEventListener('click', clear);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            clear();
        }
    });

    new ResizeObserver(() => selection && render()).observe(viewport);

    render();
}

const mounted = new WeakSet();

function mountChartBrushes() {
    document.querySelectorAll('[data-brush-field]').forEach((root) => {
        if (mounted.has(root)) {
            return;
        }

        mounted.add(root);
        createChartBrush(root);
    });
}

document.addEventListener('DOMContentLoaded', mountChartBrushes);
document.addEventListener('livewire:navigated', mountChartBrushes);
