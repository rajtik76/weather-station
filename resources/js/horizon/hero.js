import { PlzenHolidays as H } from "./holidays";
import { PlzenSky as P } from "./sky";

/**
 * Drives the Horizon sky behind `[data-horizon]`. The scene, the clock and the day bar carry wire:ignore, so a
 * Livewire poll never reaches what this paints; the detected rain and wind arrive by attribute on `[data-rain-state]`,
 * an element the page always renders so a morph changes the attribute instead of swapping the node.
 */

const SKY = [
    [-18, ["#04060e", "#070b1c", "#0b1230", "#111a3c"]],
    [-12, ["#060a1c", "#0c1436", "#16224f", "#25305f"]],
    [-6, ["#0d1638", "#222e69", "#5a4a88", "#c4667c"]],
    [-1, ["#18306a", "#4a519c", "#d17489", "#ffab6b"]],
    [4, ["#1f4c98", "#5f84c8", "#f0a882", "#ffd28e"]],
    [12, ["#1d5cb4", "#4f93dc", "#a5cdee", "#f2e4c8"]],
    [28, ["#1a5fbd", "#3f8fe0", "#8ec5f0", "#d8ecfb"]],
];
const CITY = [
    [-18, ["#0c1430", "#080e22", "#03050b"]],
    [-6, ["#4a3a72", "#251f4e", "#0a0a1e"]],
    [-1, ["#9a5a80", "#4b2f63", "#150f2c"]],
    [4, ["#b98a94", "#5a4672", "#1a1836"]],
    [12, ["#8fb2d8", "#476a9c", "#172a4c"]],
    [28, ["#86b4e2", "#3f6da6", "#14294d"]],
];
const SUN = [
    [-4, ["#ff5f2e"]],
    [0, ["#ff8a42"]],
    [5, ["#ffbf6b"]],
    [12, ["#ffe9ae"]],
    [30, ["#fffbe8"]],
];
const MURK = [
    [0, ["#0a0d18"]],
    [1, ["#5c687c"]],
];
const CLOUD = [
    [-12, ["#1b2547"]],
    [-3, ["#c97a93"]],
    [2, ["#ffb9a0"]],
    [12, ["#ffffff"]],
];
const CLOUD_SPEED = { calm: 1, breeze: 3, strong: 7 };
const LEAF_COLOURS = ["#d98a3a", "#b5651d", "#e0b04a", "#8a4b22"];
const PREVIEW_TEXT = {
    light: "Light rain, preview",
    heavy: "Heavy rain, preview",
    breeze: "Breeze, preview",
    strong: "Strong wind, preview",
};

function mount(hero) {
    const root = document.documentElement;
    const scene = hero.querySelector("[data-scene]");
    const find = (name) => hero.querySelector(`[data-${name}]`);
    const text = (name, value) =>
        hero.querySelectorAll(`[data-${name}]`).forEach((node) => (node.textContent = value));

    find("city").innerHTML = P.skyline({
        back: '<g data-holiday-layer="sky"></g>',
        mid: '<g class="hol-mid" data-holiday-layer="mid"></g>',
        front: '<g class="hol-near" data-holiday-layer="near"></g>',
    });
    find("stars").innerHTML = P.scatter(120)
        .map(
            (star) =>
                `<i style="left:${star.x}%;top:${star.y}%;width:${star.size}px;height:${star.size}px;animation-delay:-${star.delay}s"></i>`,
        )
        .join("");
    find("leaves").innerHTML = P.scatter(16, 31)
        .map(
            (leaf, index) =>
                `<i style="--y:${(8 + leaf.y).toFixed(1)}%;--t:${(2.2 + leaf.size).toFixed(2)}s;--d:-${leaf.delay.toFixed(2)}s;--dy:${((leaf.x - 50) * 3).toFixed(0)}px;--c:${LEAF_COLOURS[index % 4]}"></i>`,
        )
        .join("");

    const arcDay = find("arc-day");
    const arcPassed = find("arc-passed");
    const arcMoon = find("arc-moon");
    const hours = find("hours");
    const moonTurn = find("moon-turn");
    const moonLit = find("moon-lit");
    const moonLabel = find("moon-label");
    const rail = find("rail");
    const holidayLayers = [...hero.querySelectorAll("[data-holiday-layer]")];
    const holidayName = find("holiday-name");
    const holidayHint = find("holiday-hint");
    const holidayPick = find("holiday-pick");
    const rainPick = find("rain-pick");
    const windPick = find("wind-pick");
    let drawn = null;
    let shownHoliday;

    function showHoliday(holiday) {
        const key = holiday === null ? null : holiday.key;
        if (key === shownHoliday) {
            return;
        }
        shownHoliday = key;
        const layers = H.props(holiday);
        holidayLayers.forEach((layer) => (layer.innerHTML = layers[layer.dataset.holidayLayer]));
        if (holidayName) {
            holidayName.hidden = holiday === null;
            holidayName.textContent = holiday === null ? "" : holiday.name;
        }
        if (holidayHint) {
            holidayHint.hidden = holiday === null || !holiday.hint;
            holidayHint.textContent = holiday === null ? "" : holiday.hint || "";
        }
    }

    function drawDay(today) {
        arcDay?.setAttribute("points", P.arcPoints(today));
        if (hours) {
            hours.innerHTML = today.path
                .filter((point) => point.minute % 60 === 0 && point.elevation > 2)
                .map(
                    (point) =>
                        `<span class="hz-hour hz-pin" style="--px:${point.x};--py:${point.y}">${point.minute / 60}</span>`,
                )
                .join("");
        }
        if (rail) {
            rail.style.setProperty(
                "--day-gradient",
                P.dayGradient(today, (elevation) => `rgb(${P.palette(SKY, elevation)[2]})`),
            );
            rail.style.setProperty("--rise", (today.sunrise / 14.4).toFixed(2) + "%");
            rail.style.setProperty("--set", (today.sunset / 14.4).toFixed(2) + "%");
        }
        text("sunrise", P.minuteLabel(today.sunrise));
        text("sunset", P.minuteLabel(today.sunset));
    }

    function moonNote(state, moon, phase) {
        if (moon.elevation > 0) {
            return `Moon ${P.figure(moon.elevation)}° up in the ${P.compass(moon.azimuth)}, ${phase.toLowerCase()}`;
        }
        const rise = P.nextMoonrise(state.today, state.ms);

        return (
            `${phase}, moon below the horizon` +
            (rise === null ? "" : `, rises ${P.timeLabel(rise)}`)
        );
    }

    function render(state) {
        if (state.today !== drawn) {
            drawDay(state.today);
            drawn = state.today;
        }
        const set = (name, value) => root.style.setProperty(name, value);
        P.palette(SKY, state.elevation).forEach((colour, index) => set("--s" + index, colour));
        const city = P.palette(CITY, state.elevation);
        set("--far", city[0]);
        set("--mid", city[1]);
        set("--near", city[2]);
        set("--sun", P.palette(SUN, state.elevation)[0]);
        set("--cloud", P.palette(CLOUD, state.elevation)[0]);
        set("--sun-x", state.x.toFixed(4));
        set("--sun-y", state.y.toFixed(4));
        set("--stars", state.night.toFixed(3));
        set("--daylight", state.daylight.toFixed(3));
        set("--murk", P.palette(MURK, state.daylight)[0]);
        set("--lights", (1 - P.ramp(state.elevation, -6, 2)).toFixed(3));
        set("--golden", (state.golden * 0.9).toFixed(3));
        set("--sun-glow", Math.max(state.golden * 0.85, state.daylight * 0.5).toFixed(3));
        set("--day-ui", (1 - state.night).toFixed(3));
        showHoliday(H.at(state.ms));
        arcPassed?.setAttribute("points", state.up ? P.arcPoints(state.today, state.minute) : "");
        text("time", P.minuteLabel(state.minute));
        const calendar = P.calendar(state.ms);
        text("weekday", calendar.weekday);
        text("date", calendar.date);

        const moon = P.moon(state.ms);
        const phase = `${P.moonPhaseName(moon.phase)}, ${P.figure(moon.fraction * 100)} % lit`;
        const moonShown = moon.elevation > -1.5 ? 1 - P.ramp(state.elevation, -5, 2) : 0;
        set("--moon-x", Math.min(1.15, Math.max(-0.15, moon.x)).toFixed(4));
        set("--moon-y", moon.y.toFixed(4));
        set("--moon-on", moonShown.toFixed(3));
        set("--moon-lit", moon.fraction.toFixed(3));
        moonLit?.setAttribute("d", P.moonShape(moon.fraction, 20));
        moonTurn?.setAttribute("transform", `rotate(${(-moon.limb).toFixed(1)})`);
        if (moonLabel) {
            moonLabel.textContent = phase;
        }
        arcMoon?.setAttribute(
            "points",
            moonShown > 0 ? P.moonArcPoints(state.today, state.ms) : "",
        );
        text(
            "sun",
            state.up
                ? P.sentence(state) + ", azimuth " + P.figure(state.azimuth) + "°"
                : moonNote(state, moon, phase),
        );
    }

    function detected(name, fallback) {
        return (
            hero.querySelector(`[data-${name}-state]`)?.getAttribute(`data-${name}-state`) ||
            fallback
        );
    }

    function showPreview(name, value) {
        const chip = find(`preview-${name}`);
        if (chip) {
            chip.hidden = !PREVIEW_TEXT[value];
            chip.querySelector("span").textContent = PREVIEW_TEXT[value] || "";
        }
    }

    function showWeather() {
        const rainPreview = rainPick && rainPick.value !== "detected" ? rainPick.value : null;
        const windPreview = windPick && windPick.value !== "detected" ? windPick.value : null;
        const wind = windPreview ?? detected("wind", "calm");
        scene.dataset.rain = rainPreview ?? detected("rain", "none");
        scene.dataset.wind = wind;
        showPreview("rain", rainPreview);
        showPreview("wind", windPreview);
        scene.querySelectorAll(".hz-cloud").forEach((cloud) => {
            cloud
                .getAnimations?.()
                .forEach((animation) => (animation.playbackRate = CLOUD_SPEED[wind]));
        });
    }

    hero.addEventListener("pointermove", (event) => {
        root.style.setProperty("--mx", ((event.clientX / window.innerWidth) * 2 - 1).toFixed(3));
    });

    const clock = P.clock({
        root: find("clock") ?? scene,
        render,
        range: find("scrub"),
        play: find("play"),
        now: find("now"),
    });

    if (holidayPick) {
        const realToday = P.dayStart(Date.now());
        holidayPick.insertAdjacentHTML(
            "beforeend",
            H.upcoming(Date.now())
                .map(
                    (holiday) =>
                        `<option value="${P.dayStart(holiday.ms) - realToday}">${holiday.day}.${holiday.month}.${holiday.year} ${holiday.name}</option>`,
                )
                .join(""),
        );
        holidayPick.addEventListener("change", () => clock.setOffset(Number(holidayPick.value)));
    }
    rainPick?.addEventListener("change", showWeather);
    windPick?.addEventListener("change", showWeather);
    new MutationObserver(showWeather).observe(hero, {
        subtree: true,
        attributes: true,
        attributeFilter: ["data-rain-state", "data-wind-state"],
    });
    showWeather();
}

document.querySelectorAll("[data-horizon]").forEach(mount);
