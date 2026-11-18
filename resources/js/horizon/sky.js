/**
 * The Horizon sky: sun and moon over Plzeň, the day clock and the skyline drawing.
 */

const SITE = { latitude: 49.733242, longitude: 13.399911, zone: "Europe/Prague" };
const VIEW = { azimuthMin: 40, azimuthMax: 320, elevationMax: 66 };
const RAD = Math.PI / 180;
const DAY_MINUTES = 1440;
const MINUTE_MS = 60000;
const HORIZON_DIP = -0.833;

/** NOAA approximation, the same one as App\ValueObject\SolarPosition. */
function sun(ms) {
    const date = new Date(ms);
    const yearStart = Date.UTC(date.getUTCFullYear(), 0, 1);
    const dayOfYear = Math.floor((ms - yearStart) / 864e5) + 1;
    const hours = date.getUTCHours() + date.getUTCMinutes() / 60 + date.getUTCSeconds() / 3600;
    const g = ((2 * Math.PI) / 365) * (dayOfYear - 1 + (hours - 12) / 24);
    const equation =
        229.18 *
        (0.000075 +
            0.001868 * Math.cos(g) -
            0.032077 * Math.sin(g) -
            0.014615 * Math.cos(2 * g) -
            0.040849 * Math.sin(2 * g));
    const declination =
        0.006918 -
        0.399912 * Math.cos(g) +
        0.070257 * Math.sin(g) -
        0.006758 * Math.cos(2 * g) +
        0.000907 * Math.sin(2 * g) -
        0.002697 * Math.cos(3 * g) +
        0.00148 * Math.sin(3 * g);
    const hourAngle = ((hours * 60 + equation + 4 * SITE.longitude) / 4 - 180) * RAD;
    const latitude = SITE.latitude * RAD;
    const sineElevation =
        Math.sin(latitude) * Math.sin(declination) +
        Math.cos(latitude) * Math.cos(declination) * Math.cos(hourAngle);
    const azimuth =
        Math.atan2(
            Math.sin(hourAngle),
            Math.cos(hourAngle) * Math.sin(latitude) - Math.tan(declination) * Math.cos(latitude),
        ) /
            RAD +
        180;

    return { azimuth: (azimuth + 360) % 360, elevation: Math.asin(sineElevation) / RAD };
}

const zoneParts = new Intl.DateTimeFormat("en-GB", {
    timeZone: SITE.zone,
    hourCycle: "h23",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
});

function zoneOffsetMinutes(ms) {
    const parts = {};
    for (const part of zoneParts.formatToParts(new Date(ms))) {
        parts[part.type] = Number(part.value);
    }
    const asUtc = Date.UTC(
        parts.year,
        parts.month - 1,
        parts.day,
        parts.hour % 24,
        parts.minute,
        parts.second,
    );

    return Math.round((asUtc - ms) / MINUTE_MS);
}

function dayStart(ms) {
    const offset = zoneOffsetMinutes(ms) * MINUTE_MS;

    return Math.floor((ms + offset) / 864e5) * 864e5 - offset;
}

/** One local day: sunrise, sunset and solar noon in minutes after midnight, plus the path every 5 minutes. */
function day(ms) {
    const start = dayStart(ms);
    const path = [];
    let sunrise = null;
    let sunset = null;
    let noon = 0;
    let peak = -90;
    let previous = null;

    for (let minute = 0; minute <= DAY_MINUTES; minute++) {
        const position = sun(start + minute * MINUTE_MS);
        if (previous !== null && previous < HORIZON_DIP && position.elevation >= HORIZON_DIP) {
            sunrise = minute;
        }
        if (previous !== null && previous >= HORIZON_DIP && position.elevation < HORIZON_DIP) {
            sunset = minute;
        }
        if (position.elevation > peak) {
            peak = position.elevation;
            noon = minute;
        }
        if (minute % 5 === 0) {
            path.push({ minute, ...position, ...project(position.azimuth, position.elevation) });
        }
        previous = position.elevation;
    }

    return { start, sunrise, sunset, noon, peak, path };
}

/** Looking south: east on the left edge, west on the right, x and y as fractions of the sky box. */
function project(azimuth, elevation) {
    return {
        x: (azimuth - VIEW.azimuthMin) / (VIEW.azimuthMax - VIEW.azimuthMin),
        y: elevation / VIEW.elevationMax,
    };
}

function ramp(value, from, to) {
    const k = Math.min(1, Math.max(0, (value - from) / (to - from)));

    return k * k * (3 - 2 * k);
}

function stateAt(ms, today) {
    const position = sun(ms);
    const spot = project(position.azimuth, position.elevation);
    const minute = (ms - today.start) / MINUTE_MS;

    return {
        ms,
        minute,
        today,
        azimuth: position.azimuth,
        elevation: position.elevation,
        x: Math.min(1.15, Math.max(-0.15, spot.x)),
        y: spot.y,
        up: position.elevation > HORIZON_DIP,
        rising: minute < today.noon,
        daylight: ramp(position.elevation, -6, 6),
        night: 1 - ramp(position.elevation, -14, -3),
        golden: Math.exp(-(((position.elevation - 1) / 7) ** 2)),
    };
}

function channels(hex) {
    const value = parseInt(hex.slice(1), 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

/**
 * @param {Array<[number, string[]]>} stops elevation in degrees ascending, each with the same number of hex colours
 * @returns {string[]} one "r g b" triplet per colour, ready for rgb(var(--x))
 */
function palette(stops, elevation) {
    let lower = stops[0];
    let upper = stops[stops.length - 1];
    for (let i = 0; i < stops.length - 1; i++) {
        if (elevation >= stops[i][0] && elevation <= stops[i + 1][0]) {
            lower = stops[i];
            upper = stops[i + 1];
            break;
        }
    }
    if (elevation <= stops[0][0]) {
        upper = lower;
    }
    if (elevation >= stops[stops.length - 1][0]) {
        lower = upper;
    }
    const k = lower === upper ? 0 : (elevation - lower[0]) / (upper[0] - lower[0]);

    return lower[1].map((hex, index) => {
        const a = channels(hex);
        const b = channels(upper[1][index]);

        return a.map((channel, c) => Math.round(channel + (b[c] - channel) * k)).join(" ");
    });
}

function minuteLabel(minute) {
    const whole = ((Math.round(minute) % DAY_MINUTES) + DAY_MINUTES) % DAY_MINUTES;

    return (
        String(Math.floor(whole / 60)).padStart(2, "0") + ":" + String(whole % 60).padStart(2, "0")
    );
}

function figure(value, decimals = 0) {
    const text = Math.abs(value).toFixed(decimals).replace(".", ",");

    return value < 0 && Number(text.replace(",", ".")) !== 0 ? "−" + text : text;
}

const COMPASS = [
    "north",
    "north-east",
    "east",
    "south-east",
    "south",
    "south-west",
    "west",
    "north-west",
];

function compass(azimuth) {
    return COMPASS[Math.round(azimuth / 45) % 8];
}

function phase(state) {
    if (state.elevation < -6) {
        return "Night";
    }
    if (state.elevation < HORIZON_DIP) {
        return state.rising ? "Dawn" : "Dusk";
    }
    if (state.elevation < 6) {
        return state.rising ? "Sunrise" : "Sunset";
    }

    return "Daylight";
}

/** "Sun 14° up in the south-west" or "Sun 23° below the horizon". */
function sentence(state) {
    const degrees = figure(Math.abs(state.elevation)) + "°";

    return state.up
        ? `Sun ${degrees} up in the ${compass(state.azimuth)}`
        : `Sun ${degrees} below the horizon`;
}

function random(seed) {
    let value = seed >>> 0;

    return function () {
        value = (value * 1664525 + 1013904223) >>> 0;

        return value / 4294967296;
    };
}

/** @returns {Array<{x: number, y: number, size: number, delay: number}>} percentages inside the sky box */
function scatter(count, seed = 7) {
    const next = random(seed);

    return Array.from({ length: count }, () => ({
        x: next() * 100,
        y: next() ** 1.4 * 78,
        size: 1 + next() * 1.8,
        delay: next() * 6,
    }));
}

const BLOCKS = [
    [40, 300, 64],
    [112, 280, 52],
    [172, 310, 70],
    [1180, 296, 58],
    [1246, 312, 80],
    [1336, 286, 50],
    [1396, 318, 90],
    [1500, 304, 62],
];

/**
 * Plzeň looking south, viewBox 0 0 1600 400, ground at y 400, true horizon at y 330.
 * Left to right: Radyně ruin on its hill, housing blocks with the station balcony, the heating plant,
 * the brewery water tower and Jubilee Gate, the town hall gables, St. Bartholomew, the Franciscan spire,
 * the Great Synagogue, Škoda halls, more blocks and the Krkavec mast.
 *
 * @param {{back?: string, mid?: string, front?: string}} slots markup placed behind the city, between mid and near, in front
 */
function skyline(slots = {}) {
    const next = random(41);
    const far =
        '<path d="M0,400V326C80,318 170,296 250,290L262,288V270h4v-6h4v6h8v-6h4v6h4v18C380,296 470,330 620,334C800,338 900,312 1060,308C1180,305 1260,322 1340,318C1420,314 1470,290 1520,284L1526,283V226h2v57C1560,286 1585,292 1600,296V400Z"/>';

    let mid = "";
    let midLights = "";
    for (const [x, y, width] of BLOCKS) {
        mid += `<rect x="${x}" y="${y}" width="${width}" height="${400 - y}"/>`;
        mid += `<rect x="${x + width * 0.3}" y="${y - 5}" width="${width * 0.22}" height="5"/>`;
        const columns = Math.floor((width - 8) / 9);
        for (let row = 0; y + 7 + row * 11 < 366; row++) {
            for (let column = 0; column < columns; column++) {
                if (next() < 0.4) {
                    midLights += `<rect class="sk-win" x="${x + 6 + column * 9}" y="${y + 7 + row * 11}" width="4" height="5"/>`;
                }
            }
        }
    }
    mid +=
        '<rect x="300" y="332" width="92" height="68"/>' +
        '<path d="M350,400L353,200h6l3,200Z"/>' +
        '<path d="M394,400l2-150h5l2,150Z"/>' +
        '<path d="M462,400L465,290h14l3,110Z"/>' +
        '<rect x="458" y="282" width="28" height="8"/>' +
        '<rect x="463" y="262" width="18" height="20"/>' +
        '<path d="M459,262L472,236L485,262Z"/>' +
        '<path d="M520,400V308h8v-8h94v8h8V400h-18v-56a17,17 0 0 0-34,0v56h-12v-56a17,17 0 0 0-34,0v56Z"/>' +
        '<path d="M558,300L575,286L592,300Z"/>' +
        '<rect x="650" y="322" width="60" height="78"/>' +
        '<path d="M690,322l2-52h6l2,52Z"/>' +
        '<path d="M1098,400V340l20-14v14l20-14v14l20-14v14h22V400Z"/>' +
        '<path d="M1150,400l2-138h6l2,138Z"/>';

    let near = "";
    let nearLights = "";
    for (let x = -12; x < 1612;) {
        const width = 24 + next() * 30;
        const wall = 12 + next() * 16;
        const roof = 8 + next() * 7;
        const top = 400 - wall;
        const shape = next();
        if (shape < 0.6) {
            near += `<path d="M${x.toFixed(1)},400V${top.toFixed(1)}l${(width / 2).toFixed(1)}-${roof.toFixed(1)} ${(width / 2).toFixed(1)},${roof.toFixed(1)}V400Z"/>`;
        } else if (shape < 0.85) {
            near += `<path d="M${x.toFixed(1)},400V${top.toFixed(1)}l5-${roof.toFixed(1)}h${(width - 10).toFixed(1)}l5,${roof.toFixed(1)}V400Z"/>`;
        } else {
            near += `<rect x="${x.toFixed(1)}" y="${(top - roof * 0.5).toFixed(1)}" width="${width.toFixed(1)}" height="${(wall + roof * 0.5).toFixed(1)}"/>`;
        }
        if (next() < 0.55) {
            nearLights += `<rect class="sk-win" x="${(x + 5 + next() * (width - 13)).toFixed(1)}" y="${(top + 3).toFixed(1)}" width="3" height="4"/>`;
        }
        x += width - 1;
    }
    for (let i = 0; i < 46; i++) {
        const x = next() * 1600;
        const radius = 7 + next() * 8;
        near += `<ellipse class="sk-tree" cx="${x.toFixed(1)}" cy="${(400 - radius * 0.6).toFixed(1)}" rx="${radius.toFixed(1)}" ry="${(radius * 0.85).toFixed(1)}"/>`;
    }
    const landmarks =
        '<path d="M690,400V330l10-22 10,22h1l10-30 10,30h1l10-22 10,22V400Z"/>' +
        '<rect x="719" y="288" width="4" height="14"/>' +
        '<path d="M758,400V306l14-16 14,16V400Z"/>' +
        '<rect x="786" y="212" width="28" height="188"/>' +
        '<rect x="782" y="206" width="36" height="7"/>' +
        '<path d="M783,206l2-14 2,14ZM813,206l2-14 2,14Z"/>' +
        '<rect x="790" y="188" width="20" height="18"/>' +
        '<path d="M789,188L800,82L811,188Z"/>' +
        '<path d="M799.2,84V70h1.6v14ZM796,73.2h8v1.6h-8Z"/>' +
        '<path d="M814,400V318l10-56h68l10,56V400Z"/>' +
        '<path d="M857,262l3-26 3,26Z"/>' +
        '<path d="M902,400V334l6-30h22l8,30V400Z"/>' +
        '<path d="M952,400V330h6l3-38 3,38h6V400Z"/>' +
        '<rect x="1012" y="296" width="18" height="104"/>' +
        '<path d="M1009,296C1005,280 1016,276 1021,262C1026,276 1037,280 1033,296Z"/>' +
        '<rect x="1020.2" y="250" width="1.6" height="13"/>' +
        '<rect x="1072" y="296" width="18" height="104"/>' +
        '<path d="M1069,296C1065,280 1076,276 1081,262C1086,276 1097,280 1093,296Z"/>' +
        '<rect x="1080.2" y="250" width="1.6" height="13"/>' +
        '<path d="M1030,400V326l21-26 21,26V400Z"/>';
    nearLights +=
        '<circle class="sk-win" cx="800" cy="232" r="3.2"/>' +
        '<rect class="sk-win" x="832" y="336" width="3" height="22"/>' +
        '<rect class="sk-win" x="850" y="336" width="3" height="22"/>' +
        '<rect class="sk-win" x="868" y="336" width="3" height="22"/>' +
        '<circle class="sk-win" cx="1051" cy="330" r="4"/>';

    return (
        (slots.back ?? "") +
        `<g class="sk-far">${far}</g>` +
        `<g class="sk-mid">${mid}</g>` +
        `<g class="sk-smoke"><circle cx="695" cy="262" r="5"/><circle cx="695" cy="262" r="5"/><circle cx="695" cy="262" r="5"/></g>` +
        `<g class="sk-lights sk-mid-lights">${midLights}</g>` +
        `<g class="sk-beacons"><circle cx="356" cy="197" r="2.2"/><circle cx="1527" cy="224" r="2.2"/><circle cx="1155" cy="260" r="2"/></g>` +
        `<g class="sk-station"><title>Balcony Station</title><circle class="sk-station-ring" cx="112" cy="306" r="4"/><circle class="sk-station-ring" cx="112" cy="306" r="4"/><circle class="sk-station-dot" cx="112" cy="306" r="3.4"/></g>` +
        (slots.mid ?? "") +
        `<g class="sk-near">${landmarks}${near}</g>` +
        `<g class="sk-lights sk-near-lights">${nearLights}</g>` +
        (slots.front ?? "")
    );
}

/**
 * Runs the hero: a sweep from midnight to now on load, then real time, a time lapse or a scrubbed minute.
 *
 * @param {{root: HTMLElement, render: Function, range?: HTMLInputElement, play?: HTMLElement, now?: HTMLElement, introMs?: number, loopMs?: number}} options
 */
function clock(options) {
    const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const speed = DAY_MINUTES / (options.loopMs ?? 40000);
    let offset = 0;
    let today = day(Date.now());
    let mode = "live";
    let minute = 0;
    let frame = 0;
    let timer = 0;
    let last = 0;

    function emit(ms) {
        const state = stateAt(ms, today);
        state.mode = mode;
        minute = state.minute;
        options.root.dataset.mode = mode;
        if (options.range && document.activeElement !== options.range) {
            options.range.value = String(Math.round(minute));
        }
        if (options.range) {
            options.range.setAttribute("aria-valuetext", minuteLabel(minute));
        }
        if (options.play) {
            options.play.setAttribute("aria-pressed", String(mode === "play"));
            const label = options.play.querySelector("[data-label]");
            if (label) {
                label.textContent =
                    mode === "play"
                        ? options.play.dataset.pauseLabel
                        : options.play.dataset.playLabel;
            }
        }
        if (options.now) {
            options.now.hidden = mode === "live" || mode === "intro";
        }
        options.render(state);
    }

    function halt() {
        cancelAnimationFrame(frame);
        clearInterval(timer);
    }

    function current() {
        const ms = Date.now() + offset;
        if (ms < today.start || ms - today.start >= 864e5) {
            today = day(ms);
        }

        return ms;
    }

    function live() {
        halt();
        mode = "live";
        emit(current());
        timer = setInterval(() => emit(current()), 1000);
    }

    function play() {
        halt();
        mode = "play";
        last = performance.now();
        frame = requestAnimationFrame(function tick(time) {
            minute = (minute + (time - last) * speed) % DAY_MINUTES;
            last = time;
            emit(today.start + minute * MINUTE_MS);
            frame = requestAnimationFrame(tick);
        });
    }

    function scrub(value) {
        halt();
        mode = "scrub";
        emit(today.start + value * MINUTE_MS);
    }

    function intro() {
        if (reduced) {
            live();

            return;
        }
        mode = "intro";
        const target = (current() - today.start) / MINUTE_MS;
        const duration = options.introMs ?? 3800;
        let began = 0;
        frame = requestAnimationFrame(function step(time) {
            began = began || time;
            const k = Math.min(1, (time - began) / duration);
            const eased = k < 0.5 ? 4 * k * k * k : 1 - (-2 * k + 2) ** 3 / 2;
            emit(today.start + target * eased * MINUTE_MS);
            if (k < 1) {
                frame = requestAnimationFrame(step);
            } else {
                live();
            }
        });
    }

    if (options.range) {
        options.range.min = "0";
        options.range.max = String(DAY_MINUTES - 1);
        options.range.addEventListener("input", () => scrub(Number(options.range.value)));
    }
    if (options.play) {
        options.play.addEventListener("click", () => (mode === "play" ? scrub(minute) : play()));
    }
    if (options.now) {
        options.now.addEventListener("click", live);
    }

    /** Shifts the whole scene by `value` ms, keeping the mode and the time of day. */
    function setOffset(value) {
        offset = value;
        const ms = current();
        if (mode === "live") {
            emit(ms);
        } else if (mode === "scrub") {
            emit(today.start + minute * MINUTE_MS);
        }
    }

    intro();

    return { live, play, scrub, setOffset, today: () => today };
}

/** CSS gradient of the day, one stop per half hour, coloured by `colour(elevation)`. */
function dayGradient(today, colour) {
    const stops = today.path
        .filter((point) => point.minute % 30 === 0)
        .map(
            (point) =>
                `${colour(point.elevation)} ${((point.minute / DAY_MINUTES) * 100).toFixed(2)}%`,
        );

    return `linear-gradient(90deg, ${stops.join(", ")})`;
}

/** Points of the path above the horizon for an SVG with viewBox 0 0 1000 1000 stretched over the sky box. */
function arcPoints(today, untilMinute = DAY_MINUTES) {
    return today.path
        .filter((point) => point.elevation > -1.5 && point.minute <= untilMinute)
        .map((point) => `${(point.x * 1000).toFixed(1)},${((1 - point.y) * 1000).toFixed(1)}`)
        .join(" ");
}

/** Last ten-minute summary before now, the station's upload rhythm. */
function lastUpload(ms = Date.now()) {
    return minuteLabel(Math.floor((ms - dayStart(ms)) / MINUTE_MS / 10) * 10);
}

const J2000_MS = Date.UTC(2000, 0, 1, 12);
const OBLIQUITY = 23.4397 * RAD;
const SUN_DISTANCE_KM = 149598000;
const EARTH_RADIUS_KM = 6378.14;

function equatorial(longitude, latitude) {
    return {
        rightAscension: Math.atan2(
            Math.sin(longitude) * Math.cos(OBLIQUITY) - Math.tan(latitude) * Math.sin(OBLIQUITY),
            Math.cos(longitude),
        ),
        declination: Math.asin(
            Math.sin(latitude) * Math.cos(OBLIQUITY) +
                Math.cos(latitude) * Math.sin(OBLIQUITY) * Math.sin(longitude),
        ),
    };
}

function moonCoordinates(days) {
    const meanLongitude = (218.316 + 13.176396 * days) * RAD;
    const meanAnomaly = (134.963 + 13.064993 * days) * RAD;
    const meanDistance = (93.272 + 13.22935 * days) * RAD;

    return {
        ...equatorial(
            meanLongitude + 6.289 * RAD * Math.sin(meanAnomaly),
            5.128 * RAD * Math.sin(meanDistance),
        ),
        distance: 385001 - 20905 * Math.cos(meanAnomaly),
    };
}

function sunCoordinates(days) {
    const meanAnomaly = (357.5291 + 0.98560028 * days) * RAD;
    const centre =
        (1.9148 * Math.sin(meanAnomaly) +
            0.02 * Math.sin(2 * meanAnomaly) +
            0.0003 * Math.sin(3 * meanAnomaly)) *
        RAD;

    return equatorial(meanAnomaly + centre + 102.9372 * RAD + Math.PI, 0);
}

/**
 * Low-precision lunar theory (the SunCalc formulas) with topocentric parallax, good to about half a degree.
 * `limb` is the bright limb's angle from the zenith, counter-clockwise as seen in the sky.
 *
 * @returns {{azimuth: number, elevation: number, fraction: number, phase: number, limb: number, x: number, y: number}}
 */
function moon(ms) {
    const days = (ms - J2000_MS) / 864e5;
    const latitude = SITE.latitude * RAD;
    const body = moonCoordinates(days);
    const star = sunCoordinates(days);
    const hourAngle =
        (280.16 + 360.9856235 * days) * RAD + SITE.longitude * RAD - body.rightAscension;
    const geocentric = Math.asin(
        Math.sin(latitude) * Math.sin(body.declination) +
            Math.cos(latitude) * Math.cos(body.declination) * Math.cos(hourAngle),
    );
    const elevation =
        (geocentric - Math.asin(EARTH_RADIUS_KM / body.distance) * Math.cos(geocentric)) / RAD;
    const azimuth =
        Math.atan2(
            Math.sin(hourAngle),
            Math.cos(hourAngle) * Math.sin(latitude) -
                Math.tan(body.declination) * Math.cos(latitude),
        ) /
            RAD +
        180;
    const parallactic = Math.atan2(
        Math.sin(hourAngle),
        Math.tan(latitude) * Math.cos(body.declination) -
            Math.sin(body.declination) * Math.cos(hourAngle),
    );
    const separation = Math.acos(
        Math.sin(star.declination) * Math.sin(body.declination) +
            Math.cos(star.declination) *
                Math.cos(body.declination) *
                Math.cos(star.rightAscension - body.rightAscension),
    );
    const incidence = Math.atan2(
        SUN_DISTANCE_KM * Math.sin(separation),
        body.distance - SUN_DISTANCE_KM * Math.cos(separation),
    );
    const limbFromNorth = Math.atan2(
        Math.cos(star.declination) * Math.sin(star.rightAscension - body.rightAscension),
        Math.sin(star.declination) * Math.cos(body.declination) -
            Math.cos(star.declination) *
                Math.sin(body.declination) *
                Math.cos(star.rightAscension - body.rightAscension),
    );
    const normalised = ((azimuth % 360) + 360) % 360;

    return {
        azimuth: normalised,
        elevation,
        fraction: (1 + Math.cos(incidence)) / 2,
        phase: 0.5 + (0.5 * incidence * (limbFromNorth < 0 ? -1 : 1)) / Math.PI,
        limb: (limbFromNorth - parallactic) / RAD,
        ...project(normalised, elevation),
    };
}

const MOON_PHASES = [
    [0.03, "New moon"],
    [0.22, "Waxing crescent"],
    [0.28, "First quarter"],
    [0.47, "Waxing gibbous"],
    [0.53, "Full moon"],
    [0.72, "Waning gibbous"],
    [0.78, "Last quarter"],
    [0.97, "Waning crescent"],
];

function moonPhaseName(phase) {
    const match = MOON_PHASES.find(([limit]) => phase < limit);

    return match ? match[1] : "New moon";
}

/** Lit part of a disc centred on 0,0 with the bright limb pointing up; rotate it by -limb degrees. */
function moonShape(fraction, radius) {
    const terminator = (Math.abs(1 - 2 * fraction) * radius).toFixed(2);
    const sweep = fraction > 0.5 ? 1 : 0;

    return `M${-radius},0A${radius},${radius} 0 0 1 ${radius},0A${radius},${terminator} 0 0 ${sweep} ${-radius},0Z`;
}

const MOON_STEP_MS = 10 * MINUTE_MS;
let moonTrackCache = null;

/** Moon every 10 minutes from 12 h before the local day to 12 h after it. */
function moonTrack(today) {
    if (moonTrackCache === null || moonTrackCache.start !== today.start) {
        const points = [];
        for (let ms = today.start - 432e5; ms <= today.start + 1296e5; ms += MOON_STEP_MS) {
            points.push({ ms, ...moon(ms) });
        }
        moonTrackCache = { start: today.start, points };
    }

    return moonTrackCache.points;
}

/** The moon's current pass above the horizon as polyline points, empty while it is down. */
function moonArcPoints(today, ms) {
    const points = moonTrack(today);
    const index = Math.min(
        points.length - 1,
        Math.max(0, Math.round((ms - points[0].ms) / MOON_STEP_MS)),
    );
    const visible = (point) => point.elevation > -1.5 && point.x > -0.05 && point.x < 1.05;
    if (!visible(points[index])) {
        return "";
    }
    let from = index;
    let to = index;
    while (from > 0 && visible(points[from - 1])) {
        from--;
    }
    while (to < points.length - 1 && visible(points[to + 1])) {
        to++;
    }

    return points
        .slice(from, to + 1)
        .map((point) => `${(point.x * 1000).toFixed(1)},${((1 - point.y) * 1000).toFixed(1)}`)
        .join(" ");
}

/** @returns {number|null} ms of the next moonrise inside the track */
function nextMoonrise(today, ms) {
    const points = moonTrack(today);
    for (let i = 1; i < points.length; i++) {
        if (points[i].ms > ms && points[i - 1].elevation < 0 && points[i].elevation >= 0) {
            return points[i].ms;
        }
    }

    return null;
}

function timeLabel(ms) {
    return minuteLabel((ms - dayStart(ms)) / MINUTE_MS);
}

const calendarParts = new Intl.DateTimeFormat("en-GB", {
    timeZone: SITE.zone,
    weekday: "long",
    day: "numeric",
    month: "numeric",
    year: "numeric",
});

/** @returns {{weekday: string, date: string, day: number, month: number, year: number}} date in the page's j.n.Y style */
function calendar(ms) {
    const parts = {};
    for (const part of calendarParts.formatToParts(new Date(ms))) {
        parts[part.type] = part.value;
    }

    return {
        weekday: parts.weekday,
        date: `${Number(parts.day)}.${Number(parts.month)}.${parts.year}`,
        day: Number(parts.day),
        month: Number(parts.month),
        year: Number(parts.year),
    };
}

export const PlzenSky = {
    calendar,
    dayStart,
    moon,
    moonPhaseName,
    moonShape,
    moonArcPoints,
    nextMoonrise,
    timeLabel,
    SITE,
    VIEW,
    sun,
    day,
    project,
    palette,
    ramp,
    minuteLabel,
    figure,
    compass,
    phase,
    sentence,
    scatter,
    random,
    skyline,
    clock,
    dayGradient,
    arcPoints,
    lastUpload,
};
