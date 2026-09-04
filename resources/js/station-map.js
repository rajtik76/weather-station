import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const OSM_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

const INK = {
    light: '#18181b',
    dark: '#e4e4e7',
};

const isDark = () => document.documentElement.classList.contains('dark');

/**
 * Render the area the station reports from, as a circle and nothing else.
 *
 * There is deliberately no marker at the centre. A dot on the exact
 * coordinates would defeat the circle: the radius stops meaning "somewhere in
 * here" and starts meaning "here, and here is the middle of the ring".
 */
function createStationMap(el) {
    const lat = Number.parseFloat(el.dataset.lat);
    const lng = Number.parseFloat(el.dataset.lng);
    const radius = Number.parseInt(el.dataset.radius, 10);

    if (Number.isNaN(lat) || Number.isNaN(lng)) {
        return;
    }

    const map = L.map(el, {
        center: [lat, lng],
        zoom: 13,
        // Let the page scroll past the map; zoom stays on the controls.
        scrollWheelZoom: false,
        zoomControl: true,
        keyboard: true,
    });

    L.tileLayer(OSM_TILES, {
        maxZoom: 19,
        attribution: OSM_ATTRIBUTION,
    }).addTo(map);

    const ink = () => INK[isDark() ? 'dark' : 'light'];

    const area = L.circle([lat, lng], {
        radius,
        color: ink(),
        weight: 2,
        opacity: 1,
        fillColor: ink(),
        fillOpacity: 0.14,
        dashArray: '5 4',
    }).addTo(map);

    map.fitBounds(area.getBounds(), { padding: [24, 24] });

    const repaint = () => {
        area.setStyle({ color: ink(), fillColor: ink() });
    };

    const themeWatcher = new MutationObserver(repaint);
    themeWatcher.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });

    return () => {
        themeWatcher.disconnect();
        map.remove();
    };
}

const teardowns = new WeakMap();

function mountStationMaps() {
    document.querySelectorAll('[data-station-map]').forEach((el) => {
        if (teardowns.has(el)) {
            return;
        }

        const teardown = createStationMap(el);

        if (teardown) {
            teardowns.set(el, teardown);
        }
    });
}

document.addEventListener('DOMContentLoaded', mountStationMaps);
document.addEventListener('livewire:navigated', mountStationMaps);
