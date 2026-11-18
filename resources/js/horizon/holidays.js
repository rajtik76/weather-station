import { PlzenSky } from "./sky";

/**
 * Czech public holidays and their props for the skyline (viewBox 0 0 1600 400, ground at y 400).
 * Props sit in three parallax layers: sky (still), mid (moves with .sk-mid) and near (moves with .sk-near).
 */

const P = PlzenSky;
const NIGHT_HINT = "Fireworks show after dark, press Play the day.";
const RED = "#d7141a";
const BLUE = "#11457e";
const WHITE = "#ffffff";
const GOLD = "#f6c453";
const WOOD = "#5a3b2e";
const SKIN = "#f1c9a5";
const LAYERS = ["sky", "mid", "near"];

const FIXED = [
    {
        key: "new-year",
        day: 1,
        month: 1,
        name: "New Year's Day, Restoration Day of the Czech State",
        parts: ["flags", "chimneySweep", "fireworks"],
        hint: NIGHT_HINT,
    },
    {
        key: "labour-day",
        day: 1,
        month: 5,
        name: "May Day",
        parts: ["blossom", "maypole", "lovers"],
    },
    { key: "victory-day", day: 8, month: 5, name: "Victory Day", parts: ["alliedFlags", "jeep"] },
    {
        key: "cyril-methodius",
        day: 5,
        month: 7,
        name: "Saints Cyril and Methodius Day",
        parts: ["flags", "saints"],
    },
    {
        key: "jan-hus",
        day: 6,
        month: 7,
        name: "Jan Hus Day",
        parts: ["flags", "bonfire", "chalice"],
    },
    {
        key: "statehood",
        day: 28,
        month: 9,
        name: "St. Wenceslas Day, Czech Statehood Day",
        parts: ["flags", "wenceslas"],
    },
    {
        key: "independence",
        day: 28,
        month: 10,
        name: "Independent Czechoslovak State Day",
        parts: ["flags", "towerFlag", "bunting", "linden"],
    },
    {
        key: "freedom",
        day: 17,
        month: 11,
        name: "Struggle for Freedom and Democracy Day",
        parts: ["crowd", "candles", "havelHeart"],
    },
    {
        key: "christmas-eve",
        day: 24,
        month: 12,
        name: "Christmas Eve",
        parts: ["christmasTree", "carp", "bethlehemStar"],
    },
    {
        key: "christmas-day",
        day: 25,
        month: 12,
        name: "Christmas Day",
        parts: ["christmasTree", "carp", "bethlehemStar"],
    },
    {
        key: "st-stephen",
        day: 26,
        month: 12,
        name: "St. Stephen's Day",
        parts: ["christmasTree", "carp", "bethlehemStar"],
    },
];

/** Anonymous Gregorian computus. */
function easterSunday(year) {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);

    return { month: Math.floor((h + l - 7 * m + 114) / 31), day: ((h + l - 7 * m + 114) % 31) + 1 };
}

function shifted(year, sunday, days) {
    const date = new Date(Date.UTC(year, sunday.month - 1, sunday.day + days, 12));

    return { day: date.getUTCDate(), month: date.getUTCMonth() + 1 };
}

function forYear(year) {
    const sunday = easterSunday(year);
    const movable = [
        {
            key: "good-friday",
            name: "Good Friday",
            parts: ["blossom", "eggTree"],
            ...shifted(year, sunday, -2),
        },
        {
            key: "easter-monday",
            name: "Easter Monday",
            parts: ["blossom", "eggTree", "pomlazka", "basket"],
            ...shifted(year, sunday, 1),
        },
    ];

    return [...FIXED, ...movable]
        .map((holiday) => ({
            ...holiday,
            year,
            ms: Date.UTC(year, holiday.month - 1, holiday.day, 12),
        }))
        .sort((a, b) => a.ms - b.ms);
}

/** @returns {object|null} the holiday on the local date of `ms` */
function at(ms) {
    const date = P.calendar(ms);

    return (
        forYear(date.year).find(
            (holiday) => holiday.day === date.day && holiday.month === date.month,
        ) ?? null
    );
}

/** Next occurrence of each holiday on or after the local date of `ms`, in calendar order. */
function upcoming(ms) {
    const date = P.calendar(ms);
    const today = Date.UTC(date.year, date.month - 1, date.day, 12);
    const following = forYear(date.year + 1);

    return forYear(date.year)
        .map((holiday) =>
            holiday.ms >= today ? holiday : following.find((next) => next.key === holiday.key),
        )
        .sort((a, b) => a.month - b.month || a.day - b.day);
}

const n = (value) => (Math.round(value * 100) / 100).toString();

function piece(layer, matte, glow = "") {
    return { layer, matte, glow };
}

function starPath(cx, cy, outer, inner, points = 5) {
    let d = "";
    for (let k = 0; k < points * 2; k++) {
        const radius = k % 2 === 0 ? outer : inner;
        const angle = ((-90 + (k * 180) / points) * Math.PI) / 180;
        d += `${k === 0 ? "M" : "L"}${n(cx + radius * Math.cos(angle))},${n(cy + radius * Math.sin(angle))}`;
    }

    return d + "Z";
}

function heartPath(cx, cy, size) {
    const s = size;

    return (
        `M${n(cx)},${n(cy + s * 0.9)}` +
        `C${n(cx - s * 1.35)},${n(cy)} ${n(cx - s * 0.9)},${n(cy - s * 1.15)} ${n(cx)},${n(cy - s * 0.42)}` +
        `C${n(cx + s * 0.9)},${n(cy - s * 1.15)} ${n(cx + s * 1.35)},${n(cy)} ${n(cx)},${n(cy + s * 0.9)}Z`
    );
}

/** Group whose CSS transform pivots on (x, y). */
function pivot(x, y, className, style, content) {
    return `<g transform="translate(${n(x)} ${n(y)})"><g class="hol-pivot ${className}" style="${style}">${content}</g></g>`;
}

let clipSequence = 0;

/**
 * Cloth drawn with the pole at x 0 and the fly towards negative x, top edge at y 0; it waves in vertical strips.
 */
function wavingFlag(poleX, top, length, height, cloth, pole) {
    const strips = 9;
    const width = length / strips;
    let body = "";
    for (let i = 0; i < strips; i++) {
        const id = `hol-clip-${++clipSequence}`;
        const right = -i * width;
        body +=
            `<clipPath id="${id}"><rect x="${n(right - width - 0.35)}" y="-3" width="${n(width + 0.7)}" height="${n(height + 6)}"/></clipPath>` +
            `<g class="hol-wave" style="--d:-${n((strips - i) * 0.11)}s;--a:${n(height * 0.13 * ((i + 1) / strips))}px"><g clip-path="url(#${id})">${cloth}</g></g>`;
    }

    return (
        `<g transform="translate(${n(poleX)} ${n(top)})">` +
        `<rect class="hol-pole" x="-0.8" y="-2" width="1.6" height="${n(pole + 2)}"/>${body}` +
        `<circle cx="0" cy="-2.6" r="1.5" fill="${GOLD}"/></g>`
    );
}

function czechCloth(length, height) {
    return (
        `<rect x="${-length}" y="0" width="${length}" height="${height / 2}" fill="${WHITE}"/>` +
        `<rect x="${-length}" y="${height / 2}" width="${length}" height="${height / 2}" fill="${RED}"/>` +
        `<path d="M0,0L${-length / 2},${height / 2}L0,${height}Z" fill="${BLUE}"/>`
    );
}

function usCloth(length, height) {
    const stripe = height / 13;
    let cloth = `<rect x="${-length}" y="0" width="${length}" height="${height}" fill="${WHITE}"/>`;
    for (let k = 0; k < 13; k += 2) {
        cloth += `<rect x="${-length}" y="${n(k * stripe)}" width="${length}" height="${n(stripe)}" fill="#b22234"/>`;
    }
    cloth += `<rect x="${n(-length * 0.42)}" y="0" width="${n(length * 0.42)}" height="${n(stripe * 7)}" fill="#3c3b6e"/>`;
    for (let row = 0; row < 3; row++) {
        for (let column = 0; column < 4; column++) {
            cloth += `<circle cx="${n(-length * 0.42 + 1.6 + column * 2.6)}" cy="${n(1.5 + row * 2.4)}" r=".45" fill="${WHITE}"/>`;
        }
    }

    return cloth;
}

function chaliceShape(cx, cy, scale, fill) {
    return (
        `<path transform="translate(${n(cx)} ${n(cy)}) scale(${scale})" fill="${fill}" ` +
        'd="M-7,-8H7C7,-2 4,1.5 1.2,2.4V6.5H4.5V8.5H-4.5V6.5H-1.2V2.4C-4,1.5 -7,-2 -7,-8Z"/>'
    );
}

const FLAG_POSTS = [
    ["mid", 138, 247],
    ["mid", 575, 258],
    ["near", 721, 260],
    ["near", 870, 234],
    ["near", 1051, 272],
    ["mid", 1361, 253],
];

function kraslice(cx, cy, rx, ry, base, ink) {
    return (
        `<ellipse cx="${n(cx)}" cy="${n(cy)}" rx="${rx}" ry="${ry}" fill="${base}"/>` +
        `<path d="M${n(cx - rx * 0.92)},${n(cy)}l${n(rx * 0.31)},-${n(ry * 0.22)} ${n(rx * 0.31)},${n(ry * 0.22)} ${n(rx * 0.31)},-${n(ry * 0.22)} ${n(rx * 0.31)},${n(ry * 0.22)} ${n(rx * 0.31)},-${n(ry * 0.22)} ${n(rx * 0.31)},${n(ry * 0.22)}" fill="none" stroke="${ink}" stroke-width="${n(rx * 0.16)}" stroke-linejoin="round"/>` +
        `<circle cx="${n(cx)}" cy="${n(cy - ry * 0.55)}" r="${n(rx * 0.17)}" fill="${ink}"/>` +
        `<circle cx="${n(cx - rx * 0.42)}" cy="${n(cy + ry * 0.48)}" r="${n(rx * 0.14)}" fill="${ink}"/>` +
        `<circle cx="${n(cx + rx * 0.42)}" cy="${n(cy + ry * 0.48)}" r="${n(rx * 0.14)}" fill="${ink}"/>`
    );
}

const EGG_COLOURS = [
    ["#e8343f", "#ffe9a8"],
    ["#ffc93c", "#c0392b"],
    ["#3d8bfd", "#ffffff"],
    ["#7ccf5a", "#ffffff"],
    ["#a45cd6", "#ffd166"],
    ["#ff8fa3", "#ffffff"],
];

function blossomTree(x, size) {
    return (
        `<rect x="${x - 1.5}" y="${400 - size * 1.3}" width="3" height="${size * 1.3}" fill="${WOOD}"/>` +
        `<circle cx="${x}" cy="${400 - size * 1.7}" r="${size}" fill="#ffc2d6"/>` +
        `<circle cx="${x - size * 0.75}" cy="${400 - size * 1.2}" r="${size * 0.75}" fill="#ffb0c8"/>` +
        `<circle cx="${x + size * 0.8}" cy="${400 - size * 1.25}" r="${size * 0.7}" fill="#ffd9e6"/>`
    );
}

function firework(x, y, colours, delay, reach) {
    let sparks = "";
    const ring = (count, distance, colour, size) => {
        for (let k = 0; k < count; k++) {
            const angle = (k / count) * Math.PI * 2;
            sparks += `<circle class="hol-spark" style="--d:-${delay}s;--tx:${n(Math.cos(angle) * distance)}px;--ty:${n(Math.sin(angle) * distance)}px" cx="${x}" cy="${y}" r="${size}" fill="${colour}"/>`;
        }
    };
    ring(18, reach, colours[0], 1.9);
    ring(11, reach * 0.55, colours[1], 1.5);

    return (
        `<path class="hol-trail" style="--d:-${delay}s" d="M${x},372V${y}" pathLength="100" stroke="${colours[0]}" stroke-width="1.6" stroke-linecap="round" fill="none"/>` +
        `<circle class="hol-flash" style="--d:-${delay}s" cx="${x}" cy="${y}" r="${n(reach * 0.7)}" fill="${colours[0]}"/>` +
        sparks
    );
}

function bunting(x1, y1, x2, y2, sag, count) {
    const cx = (x1 + x2) / 2;
    const cy = (y1 + y2) / 2 + sag * 2;
    const colours = [WHITE, RED, BLUE];
    let pennants = "";
    for (let k = 0; k < count; k++) {
        const t = (k + 0.5) / count;
        const px = (1 - t) ** 2 * x1 + 2 * (1 - t) * t * cx + t ** 2 * x2;
        const py = (1 - t) ** 2 * y1 + 2 * (1 - t) * t * cy + t ** 2 * y2;
        pennants += pivot(
            px,
            py,
            "hol-pennant",
            `--d:-${n(k * 0.23)}s`,
            `<path d="M-3.4,0H3.4L0,9Z" fill="${colours[k % 3]}"/>`,
        );
    }

    return (
        `<path d="M${x1},${y1}Q${n(cx)},${n(cy)} ${x2},${y2}" fill="none" stroke="#e9e2cf" stroke-width=".8"/>` +
        pennants
    );
}

function horseAndRider(colour) {
    return (
        `<g fill="${colour}">` +
        '<path d="M22,30C18,22 14,14 12,8L4,14L1,18L6,21L16,16C20,20 26,24 30,28C40,30 55,30 66,30C74,30 78,36 77,44C82,48 84,58 82,66C79,58 78,52 75,48L76,58L79,72L78,80L74,80L73,72L70,58L66,54L64,66L66,80L62,80L60,66L60,54L36,54L35,66L37,80L33,80L31,66L30,54L26,52L22,62L17,64L16,61L20,58L23,50C21,44 20,36 22,30Z"/>' +
        '<path d="M12,8L13,2L16,9Z"/>' +
        '<path d="M46,30L44,9H55L57,30Z"/>' +
        '<path d="M55,10C64,16 70,26 72,36L58,31Z"/>' +
        '<circle cx="49.5" cy="4" r="4.4"/>' +
        '<path d="M47,0.5L49.5,-6L52,0.5Z"/>' +
        "</g>" +
        `<path d="M50,30L44,42L47,52" fill="none" stroke="${colour}" stroke-width="4.4" stroke-linecap="round" stroke-linejoin="round"/>` +
        `<path d="M46,13L40,21" stroke="${colour}" stroke-width="3.2" stroke-linecap="round"/>` +
        `<path d="M40,50V-46" stroke="${colour}" stroke-width="1.8"/>`
    );
}

function stWenceslasCrown() {
    const lily = (x, scale) =>
        `<g transform="translate(${x} -10) scale(${scale})" fill="${GOLD}">` +
        '<path d="M-3,0Q-4,-8 0,-14Q4,-8 3,0Z"/><path d="M-2.4,0Q-9,-3 -7.6,-9.4Q-4.2,-5.6 -0.6,-3Z"/><path d="M2.4,0Q9,-3 7.6,-9.4Q4.2,-5.6 0.6,-3Z"/></g>';

    return (
        '<path d="M-16,-11C-14,-31 14,-31 16,-11Z" fill="#9c1f2e"/>' +
        `<path d="M-16,-11C-14,-31 14,-31 16,-11M0,-10V-31" fill="none" stroke="${GOLD}" stroke-width="2.6"/>` +
        `<path d="M0,-30V-42M-4.5,-37H4.5" stroke="${GOLD}" stroke-width="2.6" stroke-linecap="round"/>` +
        lily(-12, 0.85) +
        lily(0, 1) +
        lily(12, 0.85) +
        `<rect x="-20" y="-11" width="40" height="11" rx="1.5" fill="${GOLD}"/>` +
        '<circle cx="-13" cy="-5.5" r="2.4" fill="#c0172f"/><circle cx="-4.5" cy="-5.5" r="2.1" fill="#2459c9"/>' +
        '<circle cx="4.5" cy="-5.5" r="2.1" fill="#c0172f"/><circle cx="13" cy="-5.5" r="2.4" fill="#2459c9"/>'
    );
}

function saint(x, robe, accent) {
    return (
        `<g transform="translate(${x} 400)">` +
        `<circle class="hol-halo-ring" cx="0" cy="-45" r="9.5" fill="none" stroke="${GOLD}" stroke-width="2"/>` +
        `<path d="M-10,0L-7,-32C-6,-38 6,-38 7,-32L10,0Z" fill="${robe}"/>` +
        `<path d="M-6.5,-35C-3,-31 3,-31 6.5,-35L5,-26H-5Z" fill="${accent}"/>` +
        `<circle cx="0" cy="-43" r="5.2" fill="${SKIN}"/>` +
        '<path d="M-4.6,-42C-4,-36 -1.6,-33.5 0,-33.5C1.6,-33.5 4,-36 4.6,-42C2.6,-40.5 -2.6,-40.5 -4.6,-42Z" fill="#8a8a8a"/>' +
        "</g>"
    );
}

function glagolitic(x, y, d, delay) {
    return `<g transform="translate(${x} ${y})"><path class="hol-rise" style="--d:-${delay}s" d="${d}" fill="none" stroke="${GOLD}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g>`;
}

function person(x, height, colour, raised, delay) {
    const head = 400 - height;
    let figure =
        `<circle cx="${n(x)}" cy="${n(head)}" r="4.6" fill="${colour}"/>` +
        `<path d="M${n(x - 8)},400V${n(head + 13)}Q${n(x)},${n(head + 5)} ${n(x + 8)},${n(head + 13)}V400Z" fill="${colour}"/>`;
    if (raised) {
        const handX = x + 9;
        const handY = head - 15;
        figure +=
            `<path d="M${n(x + 6)},${n(head + 12)}L${n(handX)},${n(handY)}" stroke="${colour}" stroke-width="3.2" stroke-linecap="round"/>` +
            pivot(
                handX,
                handY - 1,
                "hol-jingle",
                `--d:-${n(delay)}s`,
                '<circle cx="0" cy="3.2" r="2.8" fill="none" stroke="#dfe6f2" stroke-width="1.1"/>' +
                    '<path d="M-1.4,5.6L-3.6,12.4M-3.6,12.4h2M-3,10.6h1.6" stroke="#ffd98c" stroke-width="1.4" stroke-linecap="round"/>' +
                    '<path d="M1.6,5.6L3.2,12.8M3.2,12.8h-2M2.8,10.8h-1.6" stroke="#dfe6f2" stroke-width="1.4" stroke-linecap="round"/>',
            ) +
            `<path class="hol-glint" style="--d:-${n(delay * 1.7)}s" d="${starPath(handX + 3, handY + 9, 3.4, 0.8, 4)}" fill="#ffffff"/>`;
    }

    return figure;
}

const PARTS = {
    flags: () =>
        FLAG_POSTS.map(([layer, x, top]) =>
            piece(layer, wavingFlag(x, top, 26, 16, czechCloth(26, 16), 28)),
        ),

    alliedFlags: () =>
        FLAG_POSTS.map(([layer, x, top], index) =>
            piece(
                layer,
                wavingFlag(
                    x,
                    top,
                    26,
                    16,
                    index % 2 === 0 ? czechCloth(26, 16) : usCloth(26, 16),
                    28,
                ),
            ),
        ),

    chimneySweep: () => [
        piece(
            "near",
            '<g transform="translate(878 262) scale(1.45)">' +
                '<rect x="10" y="-16" width="9" height="16" fill="#8a4b3a"/><rect x="9" y="-18" width="11" height="3" fill="#6d3a2d"/>' +
                '<circle cx="-7" cy="-17" r="4.5" fill="none" stroke="#3a3a42" stroke-width="1.2"/>' +
                '<path d="M-4,0L-3.5,-12H-1L-1.2,0ZM1.2,0L1,-12H3.5L4,0Z" fill="#1b1b22"/>' +
                '<path d="M-5,-12L-5.6,-23Q0,-26 5.6,-23L5,-12Z" fill="#1b1b22"/>' +
                `<circle cx="0" cy="-19.5" r=".9" fill="${GOLD}"/><circle cx="0" cy="-15.5" r=".9" fill="${GOLD}"/>` +
                `<circle cx="0" cy="-27.6" r="3.7" fill="${SKIN}"/>` +
                '<rect x="-3.8" y="-37.5" width="7.6" height="7" fill="#111"/><rect x="-5.6" y="-31.4" width="11.2" height="1.6" fill="#111"/>' +
                '<path d="M-5,-22L-9,-12" stroke="#1b1b22" stroke-width="2.6" stroke-linecap="round"/>' +
                '<circle cx="-9.6" cy="-9.8" r="3.2" fill="#2a2a30" stroke="#5a5a64" stroke-width="1" stroke-dasharray="1 1.2"/>' +
                pivot(
                    5,
                    -22,
                    "hol-wave-hand",
                    "",
                    `<path d="M0,0L5,-8" stroke="#1b1b22" stroke-width="2.6" stroke-linecap="round"/><circle cx="5.6" cy="-9.2" r="1.5" fill="${SKIN}"/>`,
                ) +
                "</g>",
        ),
    ],

    fireworks: () => [
        piece(
            "sky",
            "",
            '<g class="hol-fireworks">' +
                firework(300, 120, ["#ffd166", "#ff5a5f"], 0, 46) +
                firework(560, 70, ["#ff7eb6", "#ffffff"], 1.3, 42) +
                firework(700, 150, ["#ffffff", "#8fe3ff"], 2.2, 34) +
                firework(1040, 86, ["#8fe3ff", "#ffd166"], 0.7, 46) +
                firework(1270, 130, ["#b9f18c", "#ff7eb6"], 1.8, 38) +
                firework(1450, 80, ["#ff5a5f", "#ffd166"], 2.8, 36) +
                "</g>",
        ),
    ],

    blossom: () => [
        piece(
            "near",
            [
                [60, 11],
                [150, 9],
                [250, 12],
                [340, 9],
                [430, 11],
                [505, 8],
                [610, 12],
                [665, 9],
                [1050, 11],
                [1110, 11],
                [1165, 9],
                [1420, 9],
                [1490, 11],
                [1560, 10],
            ]
                .map(([x, size]) => blossomTree(x, size))
                .join(""),
        ),
    ],

    maypole() {
        const ribbons = ["#d7141a", "#ffffff", "#2f6fd6", "#ffd166", "#5cbf5a", "#ff8fb3"];

        return [
            piece(
                "near",
                '<path d="M987,400L989,112H991L993,400Z" fill="#eadbb8"/>' +
                    '<path d="M990,52L979,80H1001Z" fill="#2e7d4a"/><path d="M990,64L976,95H1004Z" fill="#276c40"/><path d="M990,80L973,112H1007Z" fill="#215e37"/>' +
                    ribbons
                        .map((colour, index) =>
                            pivot(
                                981 + index * 3.6,
                                121,
                                "hol-ribbon",
                                `--d:-${n(index * 0.37)}s`,
                                `<path d="M0,0C3,10 -3,22 1,${34 + (index % 3) * 6}" fill="none" stroke="${colour}" stroke-width="2.4" stroke-linecap="round"/>`,
                            ),
                        )
                        .join("") +
                    '<ellipse cx="990" cy="119" rx="11.5" ry="3.4" fill="none" stroke="#3f8a3a" stroke-width="3.6"/>' +
                    '<circle cx="981" cy="120" r="1.6" fill="#ff5a7a"/><circle cx="990" cy="122.4" r="1.6" fill="#ffd166"/><circle cx="999" cy="120" r="1.6" fill="#ff5a7a"/>',
            ),
        ];
    },

    lovers() {
        let petals = "";
        for (let i = 0; i < 12; i++) {
            const x = 712 + ((i * 53) % 84);
            const y = 300 + ((i * 17) % 22);
            petals += `<ellipse class="hol-petal" style="--d:-${n(i * 0.45)}s;--sx:${((i % 5) - 2) * 9}px" cx="${x}" cy="${y}" rx="2.6" ry="1.5" fill="#ffe3ec"/>`;
        }
        let hearts = "";
        for (let i = 0; i < 4; i++) {
            hearts += `<path class="hol-heart" style="--d:-${n(i * 1.1)}s" d="${heartPath(728, 326, 4)}" fill="#ff3d6e"/>`;
        }

        return [
            piece(
                "near",
                `<path d="M768,400C769,370 768,340 772,314" stroke="${WOOD}" stroke-width="6.5" stroke-linecap="round" fill="none"/>` +
                    `<path d="M770,330C756,318 742,311 724,307" stroke="${WOOD}" stroke-width="3.6" stroke-linecap="round" fill="none"/>` +
                    '<circle cx="772" cy="284" r="30" fill="#ffc2d6"/><circle cx="740" cy="294" r="23" fill="#ffb0c8"/>' +
                    '<circle cx="801" cy="296" r="21" fill="#ffd9e6"/><circle cx="754" cy="264" r="18" fill="#ffd9e6"/>' +
                    '<circle cx="788" cy="261" r="17" fill="#ffb0c8"/><circle cx="714" cy="304" r="15" fill="#ffd9e6"/>' +
                    '<g transform="translate(728 400) scale(1.45)">' +
                    '<path d="M-11,0L-12,-17H-1.5L-2,0H-5.2L-6.4,-12L-7.6,0Z" fill="#2b3550"/>' +
                    '<path d="M-12.6,-17L-12,-35Q-7,-39 -1.6,-35L-1.4,-17Z" fill="#5d8fd8"/>' +
                    `<circle cx="-5.6" cy="-41.6" r="4.9" fill="${SKIN}"/><path d="M-10.4,-42.6C-10.4,-48 -1.6,-48.6 -1,-43.4C-3.4,-45 -7,-45 -10.4,-42.6Z" fill="#4a2e1e"/>` +
                    '<path d="M1.6,-35Q6,-39 10.6,-35L11,-24L15,0H-2L2,-24Z" fill="#e8344f"/>' +
                    `<circle cx="5" cy="-41" r="4.6" fill="${SKIN}"/><path d="M1.2,-43C2,-48 9.6,-48.4 9.8,-41.6L9.6,-35C8.6,-38 7.6,-41 6.4,-42.4C4.6,-41.6 2.6,-42 1.2,-43Z" fill="#c9862f"/>` +
                    `<path d="M-2.4,-33C1,-31 5,-31.4 8,-34" stroke="#5d8fd8" stroke-width="2.6" stroke-linecap="round" fill="none"/>` +
                    "</g>" +
                    petals,
                hearts,
            ),
        ];
    },

    jeep: () => [
        piece(
            "near",
            '<g class="hol-drive"><g transform="translate(0 400) scale(1.6)">' +
                `<path d="M48,-21L52,-56" stroke="#2c2f22" stroke-width=".8"/>` +
                pivot(
                    52,
                    -56,
                    "hol-ribbon",
                    "",
                    `<path d="M0,0H-12V7H0Z" fill="${WHITE}"/><path d="M0,3.5H-12V7H0Z" fill="${RED}"/><path d="M0,0L-6,3.5L0,7Z" fill="${BLUE}"/>`,
                ) +
                '<path d="M0,-10L1,-20L24,-21.5H50L52.5,-10Z" fill="#5f6d3c"/>' +
                '<path d="M1,-20L24,-21.5V-19L1.4,-17.6Z" fill="#76854b"/>' +
                '<path d="M23,-21L26.5,-36H28.8L25.6,-21Z" fill="#3f4828"/>' +
                '<path d="M30,-21L31,-28.5H37L38,-21Z" fill="#4b5530"/><circle cx="34" cy="-31.6" r="3.6" fill="#4b5530"/>' +
                '<path d="M30.2,-32.2A3.9,3.9 0 0 1 37.8,-32.2Z" fill="#3a4226"/>' +
                '<circle cx="54" cy="-17" r="5.6" fill="#1d1f1a"/><circle cx="54" cy="-17" r="2.2" fill="#5f6d3c"/>' +
                '<circle cx="13" cy="-15.4" r="4.2" fill="none" stroke="#f4f4ee" stroke-width=".9"/>' +
                `<path d="${starPath(13, -15.2, 3.6, 1.45)}" fill="#f4f4ee"/>` +
                '<circle cx="10" cy="-6.5" r="7" fill="#1d1f1a"/><circle cx="42" cy="-6.5" r="7" fill="#1d1f1a"/>' +
                '<g class="hol-wheel"><circle cx="10" cy="-6.5" r="3" fill="#6d7a48"/><path d="M7,-6.5H13M10,-9.5V-3.5" stroke="#1d1f1a" stroke-width="1"/></g>' +
                '<g class="hol-wheel"><circle cx="42" cy="-6.5" r="3" fill="#6d7a48"/><path d="M39,-6.5H45M42,-9.5V-3.5" stroke="#1d1f1a" stroke-width="1"/></g>' +
                '<circle class="hol-headlight" cx="1.2" cy="-15.6" r="1.6" fill="#fff4c2"/>' +
                "</g></g>",
        ),
    ],

    saints: () => [
        piece(
            "near",
            '<g transform="translate(668 400) scale(1.4) translate(-668 -400)">' +
                saint(646, "#6f4a2c", "#d9c7a0") +
                '<path d="M638,370L646,373L654,370V378L646,381L638,378Z" fill="#f4ecd6" stroke="#b89b5e" stroke-width=".8"/>' +
                '<path d="M646,373V381" stroke="#b89b5e" stroke-width=".8"/>' +
                saint(690, "#7d2a2a", "#f0e6cf") +
                `<path d="M699,398V326M694,334H704M691,342H707" stroke="${GOLD}" stroke-width="2.2" stroke-linecap="round" fill="none"/>` +
                `<circle cx="698" cy="364" r="2.4" fill="${SKIN}"/>` +
                "</g>",
            '<g transform="translate(668 400) scale(1.4) translate(-668 -400)">' +
                '<circle class="hol-halo" cx="646" cy="355" r="14"/><circle class="hol-halo" cx="690" cy="355" r="14"/>' +
                glagolitic(646, 362, "M0,-5V5M-4,-1H4M-4,-1A2,2 0 1 1 -4,3M4,-1A2,2 0 1 0 4,3", 0) +
                glagolitic(646, 362, "M0,-6A3,3 0 1 1 0,0A3,3 0 1 1 0,6M-3.5,6H3.5", 1.4) +
                glagolitic(646, 362, "M-4,-5H4M0,-5V5M-4,4A4,3 0 0 0 4,4", 2.8) +
                "</g>",
        ),
    ],

    bonfire: () => [
        piece(
            "near",
            `<path d="M612,400L640,352M668,400L640,352M624,400L644,350M656,400L636,350M632,400L642,348" stroke="${WOOD}" stroke-width="4.4" stroke-linecap="round"/>` +
                '<path d="M606,400C606,394 674,394 674,400Z" fill="#3a2a22"/>',
            '<circle class="hol-halo hol-halo-big" cx="640" cy="360" r="62"/>' +
                '<path class="hol-flame" d="M640,318C664,344 668,380 640,398C612,380 616,344 640,318Z" fill="#ff6a24"/>' +
                '<path class="hol-flame" style="--d:-.35s" d="M622,350C632,364 634,386 624,398C610,390 610,368 622,350Z" fill="#ff8a2f"/>' +
                '<path class="hol-flame" style="--d:-.6s" d="M660,346C670,362 672,384 658,398C648,388 650,366 660,346Z" fill="#ff8a2f"/>' +
                '<path class="hol-flame" style="--d:-.2s" d="M640,342C654,360 654,384 640,398C626,384 626,360 640,342Z" fill="#ffc23d"/>' +
                '<path class="hol-flame" style="--d:-.5s" d="M640,364C647,374 647,388 640,398C633,388 633,374 640,364Z" fill="#fff1b8"/>' +
                [0, -0.5, -1, -1.5, -2, -2.5, -3]
                    .map(
                        (delay, index) =>
                            `<circle class="hol-ember" style="--d:${delay}s;--sx:${((index % 4) - 1.5) * 14}px" cx="${636 + (index % 3) * 4}" cy="330" r="${1.2 + (index % 2) * 0.6}" fill="${index % 2 ? "#ffb347" : "#ffd166"}"/>`,
                    )
                    .join(""),
        ),
    ],

    chalice: () => [
        piece(
            "near",
            wavingFlag(
                694,
                196,
                36,
                24,
                '<rect x="-36" y="0" width="36" height="24" fill="#16161a"/>' +
                    chaliceShape(-18, 12, 1.15, "#d7141a"),
                204,
            ),
        ),
    ],

    wenceslas: () => [
        piece(
            "near",
            '<rect x="606" y="372" width="68" height="28" fill="#cfc6b2"/><rect x="602" y="366" width="76" height="7" fill="#e2dac8"/>' +
                '<rect x="598" y="396" width="84" height="4" fill="#b6ad98"/>' +
                '<g transform="translate(602 296) scale(.87)">' +
                horseAndRider("#4f7d6c") +
                "</g>" +
                wavingFlag(
                    637,
                    256,
                    18,
                    9,
                    '<path d="M-18,0H0V9H-18L-14,4.5Z" fill="#f2efe6"/><path d="M-11,2.2L-8.6,4.5L-11,6.8L-13.4,4.5Z" fill="#1b1b22"/>',
                    4,
                ) +
                '<g transform="translate(640 226)">' +
                stWenceslasCrown() +
                "</g>",
            '<circle class="hol-halo hol-crown-glow" cx="640" cy="206" r="30"/>',
        ),
    ],

    towerFlag: () => [
        piece(
            "near",
            pivot(
                800,
                214,
                "hol-banner",
                "",
                '<rect x="-9" y="0" width="9" height="66" fill="#ffffff"/><rect x="0" y="0" width="9" height="66" fill="#d7141a"/><path d="M-9,0H9L0,33Z" fill="#11457e"/>',
            ),
        ),
    ],

    bunting: () => [
        piece(
            "near",
            bunting(742, 312, 786, 300, 6, 6) +
                bunting(902, 316, 1012, 300, 10, 13) +
                bunting(1090, 300, 1180, 328, 9, 11),
        ),
    ],

    linden() {
        const next = P.random(19);
        let leaves = "";
        for (let k = 0; k < 64; k++) {
            const angle = next() * Math.PI * 2;
            const distance = Math.sqrt(next());
            const x = 668 + Math.cos(angle) * distance * 34;
            const y = 318 + Math.sin(angle) * distance * 28;
            const tone = ["#3f8f3a", "#4ea845", "#5fbf4f", "#357a32"][k % 4];
            leaves += `<path transform="rotate(${n(next() * 70 - 35)} ${n(x)} ${n(y)})" d="${heartPath(x, y, 4.6)}" fill="${tone}"/>`;
        }

        return [
            piece(
                "near",
                `<path d="M664,400C665,378 666,360 668,338M667,352L652,334M668,346L686,326" stroke="${WOOD}" stroke-width="5" stroke-linecap="round" fill="none"/>` +
                    leaves,
            ),
        ];
    },

    crowd() {
        const next = P.random(23);
        let people = "";
        let x = 548;
        let index = 0;
        while (x < 1108) {
            const height = 40 + next() * 9;
            people += person(
                x,
                height,
                ["#33406a", "#3b4877", "#2d3961"][index % 3],
                index % 2 === 0 || next() < 0.3,
                next() * 0.6,
            );
            x += 16 + next() * 9;
            index++;
        }

        return [piece("near", people)];
    },

    candles() {
        let matte = "";
        let glow = "";
        for (let i = 0; i < 17; i++) {
            const x = 572 + i * 31 + (i % 3) * 3;
            const height = 9 + ((i * 7) % 8);
            const top = 400 - height;
            const delay = n((i * 0.37) % 1.5);
            matte += `<rect x="${x - 3.5}" y="${top}" width="7" height="${height}" rx="1" fill="#c8102e"/><rect x="${x - 3.5}" y="${top}" width="7" height="2" fill="#e8344f"/>`;
            glow +=
                `<circle class="hol-halo" cx="${x}" cy="${top - 5}" r="10"/>` +
                `<ellipse class="hol-flame" style="--d:-${delay}s" cx="${x}" cy="${top - 5}" rx="2.8" ry="5.5" fill="#ffc24a"/>` +
                `<ellipse class="hol-flame" style="--d:-${delay}s" cx="${x}" cy="${top - 3.5}" rx="1.3" ry="2.8" fill="#fff6d6"/>`;
        }

        return [piece("near", matte, glow)];
    },

    havelHeart: () => [
        piece(
            "sky",
            "",
            '<g class="hol-beat" style="transform-origin:1010px 132px">' +
                '<path d="M1010,160C994,148 980,136 980,121C980,108 993,103 1002,110C1006,113 1009,117 1010,121C1011,116 1015,110 1021,107C1031,103 1042,110 1040,123C1038,137 1024,149 1010,160Z" fill="#e3262f"/>' +
                '<path d="M987,119C988,112 995,109 1000,113" fill="none" stroke="#ff8a8f" stroke-width="2" stroke-linecap="round"/>' +
                "</g>",
        ),
    ],

    christmasTree() {
        const lights = [];
        const garland = [
            [683, 314, 717, 314, 6],
            [674, 343, 726, 343, 8],
            [665, 373, 735, 373, 10],
        ];
        for (const [x1, y1, x2, y2, count] of garland) {
            for (let k = 0; k <= count; k++) {
                const t = k / count;
                lights.push([x1 + (x2 - x1) * t, y1 + (y2 - y1) * t + Math.sin(t * Math.PI) * 5]);
            }
        }
        const colours = ["#ffd98c", "#ff5a5f", "#8fe3ff", "#ffffff", "#b9f18c"];

        return [
            piece(
                "near",
                `<rect x="695" y="382" width="10" height="18" fill="${WOOD}"/>` +
                    '<path d="M654,388L700,330L746,388Z" fill="#17583e"/>' +
                    '<path d="M662,356L700,306L738,356Z" fill="#1d6a4b"/>' +
                    '<path d="M671,326L700,286L729,326Z" fill="#237a56"/>' +
                    garland
                        .map(
                            ([x1, y1, x2, y2]) =>
                                `<path d="M${x1},${y1}Q${(x1 + x2) / 2},${(y1 + y2) / 2 + 10} ${x2},${y2}" fill="none" stroke="#e8c15a" stroke-width="1" opacity=".8"/>`,
                        )
                        .join("") +
                    '<rect x="664" y="384" width="16" height="16" fill="#d7263d"/><path d="M672,384V400M664,391H680" stroke="#ffd166" stroke-width="2"/>' +
                    '<rect x="712" y="388" width="18" height="12" fill="#2f6fd6"/><path d="M721,388V400M712,394H730" stroke="#ffffff" stroke-width="2"/>' +
                    '<rect x="684" y="390" width="11" height="10" fill="#f2c14e"/><path d="M689.5,390V400" stroke="#d7263d" stroke-width="1.6"/>',
                lights
                    .map(
                        ([x, y], index) =>
                            `<circle class="hol-light" style="--d:-${n((index * 0.41) % 1.8)}s" cx="${n(x)}" cy="${n(y)}" r="2.4" fill="${colours[index % 5]}"/>`,
                    )
                    .join("") +
                    '<circle class="hol-halo" cx="700" cy="281" r="14"/>' +
                    `<path class="hol-star" d="${starPath(700, 281, 9, 3.8)}" fill="#ffd166"/>`,
            ),
        ];
    },

    carp: () => [
        piece(
            "near",
            '<g transform="translate(881 400) scale(1.35) translate(-881 -400)">' +
                '<path d="M850,374C850,370 912,370 912,374Z" fill="#7d8996"/>' +
                '<ellipse cx="881" cy="373" rx="29" ry="3" fill="#4f8fc4"/>' +
                '<g transform="translate(881 373)"><g class="hol-pivot hol-carp">' +
                '<path d="M-15,0C-10,-7 6,-8 12,-2L19,-8L17.6,0L19,8L12,2C6,8 -10,7 -15,0Z" fill="#c8873a"/>' +
                '<path d="M-4,-6L2,-11.4L7,-5.4Z" fill="#9c5f22"/>' +
                '<path d="M-6,-2.4a2.6,2.6 0 0 1 4.4,0M-1,-2.6a2.6,2.6 0 0 1 4.4,0M2.6,1a2.6,2.6 0 0 1 4.4,0M-4,1.2a2.6,2.6 0 0 1 4.4,0" fill="none" stroke="#e8b06a" stroke-width=".8"/>' +
                '<circle cx="-11" cy="-1.4" r="1.1" fill="#1b1b22"/>' +
                "</g></g>" +
                '<path d="M848,373H914L907,398Q881,403 855,398Z" fill="#9aa7b4"/>' +
                '<path d="M848,373H914" stroke="#d3dbe4" stroke-width="2.4" stroke-linecap="round"/>' +
                '<path d="M851,381H911M853,390H909" stroke="#86939f" stroke-width="1"/>' +
                '<path d="M847,377q-6,0 -6,6M915,377q6,0 6,6" fill="none" stroke="#86939f" stroke-width="2"/>' +
                "</g>",
            '<g transform="translate(881 400) scale(1.35) translate(-881 -400)"><g class="hol-splash">' +
                '<circle cx="866" cy="370" r="1.6" fill="#bfe3ff" style="--sx:-9px"/><circle cx="871" cy="369" r="1.3" fill="#bfe3ff" style="--sx:-4px"/>' +
                '<circle cx="876" cy="369" r="1.4" fill="#bfe3ff" style="--sx:3px"/><circle cx="880" cy="370" r="1.2" fill="#bfe3ff" style="--sx:8px"/>' +
                "</g></g>",
        ),
    ],

    bethlehemStar: () => [
        piece(
            "sky",
            "",
            '<path d="M1086,74L1010,128L1090,82Z" fill="#fff3c4" opacity=".35"/>' +
                '<path d="M1086,74L1040,132L1084,80Z" fill="#fff3c4" opacity=".22"/>' +
                '<circle class="hol-halo hol-star-glow" cx="1088" cy="72" r="22"/>' +
                `<path class="hol-star" d="${starPath(1088, 72, 16, 3.2, 4)}" fill="#fff6d6"/>` +
                `<path d="${starPath(1088, 72, 8, 2.4, 4)}" transform="rotate(45 1088 72)" fill="#fff6d6"/>`,
        ),
    ],

    eggTree() {
        let eggs = "";
        const hooks = [
            [916, 336],
            [928, 322],
            [944, 316],
            [958, 326],
            [968, 340],
            [924, 350],
            [952, 346],
            [938, 332],
            [906, 352],
        ];
        hooks.forEach(([x, y], index) => {
            const [base, ink] = EGG_COLOURS[index % EGG_COLOURS.length];
            eggs += pivot(
                x,
                y,
                "hol-swing",
                `--d:-${n(index * 0.31)}s`,
                '<path d="M0,0V6" stroke="#f2efe6" stroke-width=".7"/>' +
                    kraslice(0, 13, 5.2, 7, base, ink),
            );
        });

        return [
            piece(
                "near",
                `<path d="M938,400C938,380 939,364 938,348M938,368C928,358 918,350 906,346M938,362C950,352 962,344 970,334M938,350C932,338 928,328 928,316M938,352C946,338 950,326 958,320M926,340C922,334 916,334 912,330M955,344C962,342 966,346 970,348" stroke="${WOOD}" stroke-width="2.6" stroke-linecap="round" fill="none"/>` +
                    '<g fill="#d8ead0">' +
                    [
                        [912, 330],
                        [928, 316],
                        [958, 320],
                        [970, 334],
                        [906, 346],
                        [966, 346],
                    ]
                        .map(([x, y]) => `<ellipse cx="${x}" cy="${y}" rx="1.8" ry="2.6"/>`)
                        .join("") +
                    "</g>" +
                    eggs,
            ),
        ];
    },

    pomlazka() {
        let braid = "";
        for (let k = 0; k < 15; k++) {
            const y = -16 - k * 7;
            braid += `<path d="M-2.6,${y}L2.6,${y - 5}" stroke="#8f6a38" stroke-width="1.2"/>`;
        }
        const ribbons = ["#ff3d57", "#ffd166", "#3d8bfd", "#5cbf5a", "#ff8fb3"];

        return [
            piece(
                "near",
                '<g transform="translate(648 400) rotate(-14)"><g class="hol-pivot hol-swish">' +
                    '<path d="M-3.4,0L-1.6,-126H1.6L3.4,0Z" fill="#d8b373"/>' +
                    braid +
                    '<path d="M-3.6,-2H3.6V-16H-3.6Z" fill="#2f6fd6"/><path d="M-3.6,-6H3.6M-3.6,-11H3.6" stroke="#ffffff" stroke-width="1"/>' +
                    ribbons
                        .map((colour, index) =>
                            pivot(
                                0,
                                -124,
                                "hol-ribbon",
                                `--d:-${n(index * 0.3)}s`,
                                `<path d="M0,0C${-6 - index * 2},6 ${2 - index * 3},18 ${-8 - index * 3},${26 + index * 3}" fill="none" stroke="${colour}" stroke-width="2.8" stroke-linecap="round"/>`,
                            ),
                        )
                        .join("") +
                    "</g></g>",
            ),
        ];
    },

    basket() {
        const eggs = [
            [740, 374, 0],
            [751, 371, 2],
            [762, 374, 1],
            [746, 378, 3],
            [757, 378, 4],
        ];

        return [
            piece(
                "near",
                '<path d="M732,378C732,350 770,350 770,378" fill="none" stroke="#a4703a" stroke-width="3"/>' +
                    eggs
                        .map(([x, y, colour]) =>
                            kraslice(x, y, 6, 7.6, EGG_COLOURS[colour][0], EGG_COLOURS[colour][1]),
                        )
                        .join("") +
                    '<path d="M728,380H774L768,400H734Z" fill="#b98146"/>' +
                    '<path d="M729,386H773M731,393H771M738,380V400M746,380V400M754,380V400M762,380V400" stroke="#8a5a2b" stroke-width="1"/>',
            ),
        ];
    },
};

/**
 * SVG markup of a holiday's props per parallax layer; non-luminous parts in .hol-matte, lights in .hol-glow.
 *
 * @returns {{sky: string, mid: string, near: string}}
 */
function props(holiday) {
    const layers = { sky: "", mid: "", near: "" };
    if (holiday === null) {
        return layers;
    }
    const pieces = holiday.parts.flatMap((part) => PARTS[part]());
    for (const layer of LAYERS) {
        const own = pieces.filter((built) => built.layer === layer);
        if (own.length > 0) {
            layers[layer] =
                `<g class="hol-matte">${own.map((built) => built.matte).join("")}</g>` +
                `<g class="hol-glow">${own.map((built) => built.glow).join("")}</g>`;
        }
    }

    return layers;
}

export const PlzenHolidays = { at, upcoming, props, easterSunday };
