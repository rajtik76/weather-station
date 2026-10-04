import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { token } from "./charts/theme";

const OSM_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

const OSM_TILES = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";

function ink() {
    return token("--ink") || "#18181b";
}

/** A circle and no marker at the centre: a dot would mark the exact coordinates. */
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
        dragging: false,
        touchZoom: false,
        doubleClickZoom: false,
        scrollWheelZoom: false,
        boxZoom: false,
        keyboard: false,
        zoomControl: false,
    });

    L.tileLayer(OSM_TILES, {
        maxZoom: 19,
        attribution: OSM_ATTRIBUTION,
    }).addTo(map);

    const area = L.circle([lat, lng], {
        radius,
        color: ink(),
        weight: 2,
        opacity: 1,
        fillColor: ink(),
        fillOpacity: 0.14,
        dashArray: "5 4",
        interactive: false,
    }).addTo(map);

    map.fitBounds(area.getBounds(), { padding: [24, 24] });

    const repaint = () => {
        area.setStyle({ color: ink(), fillColor: ink() });
    };

    const themeWatcher = new MutationObserver(repaint);
    themeWatcher.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ["class"],
    });

    return () => {
        themeWatcher.disconnect();
        map.remove();
    };
}

const teardowns = new WeakMap();

function mountStationMaps() {
    document.querySelectorAll("[data-station-map]").forEach((el) => {
        if (teardowns.has(el)) {
            return;
        }

        const teardown = createStationMap(el);

        if (teardown) {
            teardowns.set(el, teardown);
        }
    });
}

document.addEventListener("DOMContentLoaded", mountStationMaps);
document.addEventListener("livewire:navigated", mountStationMaps);
