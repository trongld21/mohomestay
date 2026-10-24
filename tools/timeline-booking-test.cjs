const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const hour = 3600000;
const date = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
const base = Date.parse(date + "T00:00:00+07:00");
const room = { id: "r", name: "Room", slug: "room", active: true, intervals: [{ start: new Date(base + 10 * hour).toISOString(), end: new Date(base + 11 * hour).toISOString(), status: "CLEANING" }] };
const snapshot = { connected: true, rangeStart: new Date(base).toISOString(), rangeEnd: new Date(base + 24 * hour).toISOString(), serverTime: new Date().toISOString(), rooms: [room] };
const options = [{ id: "short", name: "Short", price: 100000, start: new Date(base + 13 * hour).toISOString(), end: new Date(base + 16 * hour).toISOString(), date, time: "13:00" }];
const listeners = {};
const nodes = new Map();
let cells = [];
let currentOptions = options;
let deferred = null;
let released = false;
let popupOpen = false;
function node() {
    return {
        value: "1", hidden: false, disabled: false, dataset: {}, style: {}, textContent: "", innerHTML: "",
        showPopover() { popupOpen = true; },
        hidePopover() { popupOpen = false; },
        getBoundingClientRect: () => ({ left: 200, right: 280, top: 100, bottom: 144, width: 80, height: 44 }),
        setAttribute() {}, addEventListener() {}, append() {}, focus() {}, scrollIntoView() {},
        insertAdjacentHTML(position, html) { this.innerHTML += html; },
        querySelector: () => null,
        querySelectorAll: () => [],
    };
}
const grid = node();
Object.defineProperty(grid, "innerHTML", {
    set(value) {
        this.html = value;
        cells = [...value.matchAll(/data-slot-start="(\d+)" data-slot-room="([^"]+)"/g)].map((match) => ({ ...node(), dataset: { slotStart: match[1], slotRoom: match[2] } }));
    },
    get() { return this.html; },
});
grid.querySelectorAll = () => cells;
nodes.set("[data-timeline-grid]", grid);
const root = { dataset: { staff: "false", roomId: "r" }, querySelector(selector) {
    if (selector === "[data-timeline-search]") return null;
    if (!nodes.has(selector)) nodes.set(selector, node());
    return nodes.get(selector);
}, addEventListener(name, fn) { listeners[name] = fn; } };
const location = { hash: "", href: "" };
const context = vm.createContext({
    window: { innerWidth: 1000, innerHeight: 800, addEventListener() {}, LANG_HOME: { rooms: [room], abilities: ["availability.hold"], csrf: "test-token" } },
    document: { querySelectorAll: () => [root], addEventListener() {}, createElement: node },
    location, Intl, Date, URLSearchParams, AbortController, setInterval() {},
    fetch: async (url, config = {}) => {
        let body;
        if (url === "/api/admin/room-operations/release") {
            assert.equal(config.method, "POST");
            assert.equal(config.headers["X-CSRF-Token"], "test-token");
            assert.equal(JSON.parse(config.body).holdId, "hold");
            released = true;
            body = { ok: true };
        } else if (url.startsWith("/api/admin/room-operations/holds")) {
            body = { holds: [{ id: "hold", room_id: "r", starts_at: "2027-01-01 12:00:00", ends_at: "2027-01-01 13:00:00", expires_at: "2027-01-01 13:00:00" }] };
            if (released) body.holds = [];
        } else if (url.startsWith("/api/availability/packages")) {
            if (deferred) {
                const wait = deferred;
                deferred = null;
                return wait;
            }
            body = { packages: currentOptions };
        } else {
            const days = Number(new URLSearchParams(url.split("?")[1]).get("days"));
            body = { ...snapshot, rangeEnd: new Date(base + days * 24 * hour).toISOString() };
        }
        return { ok: true, json: async () => JSON.parse(JSON.stringify(body)) };
    },
});
vm.runInContext(fs.readFileSync("public/assets/room-timeline.js", "utf8"), context);
const settle = () => new Promise((resolve) => setImmediate(resolve));
const click = (selector, target) => listeners.click({ target: { closest: (value) => value === selector ? target : null } });
const element = (selector) => nodes.get(selector);
async function openHour(at = 13) {
    const cell = cells.find((item) => Number(item.dataset.slotStart) === base + at * hour);
    await click("[data-slot-start]", cell);
}
(async () => {
    await settle();
    assert.match(grid.innerHTML, /10:00–11:00/, "Column headers must show the whole occupied hour");
    assert.match(grid.innerHTML, /data-state="cleaning"[^>]*>Bận<\/button>/, "Cleaning cells must use the busy label");
    assert.equal(element("[data-timeline-holds-section]").hidden, false);
    assert.match(element("[data-timeline-holds]").innerHTML, /data-release-hold="hold"/, "Room page must show an unlock action with hold permission alone");
    await click("[data-release-hold]", { dataset: { releaseHold: "hold" }, disabled: false });
    assert.equal(released, true);
    assert.equal(element("[data-timeline-holds]").innerHTML, "", "Unlock must refresh the room calendar and remove the hold card");
    await openHour();
    assert.equal(popupOpen, true, "Package choices must open in an anchored popup");
    assert.equal(element("[data-timeline-booking]").style.top, "152px");
    assert.equal(element("[data-timeline-booking]").style.maxHeight, "640px", "Popup height must be limited to the space beside its anchor");
    assert.match(element("[data-timeline-packages]").innerHTML, /data-timeline-package="short"/);
    assert.equal(element("[data-timeline-booking-confirm]").hidden, true);
    await click("[data-timeline-package]", { dataset: { timelinePackage: "short" } });
    assert.equal(cells.filter((cell) => cell.dataset.selected === "true").length, 3);
    assert.equal(element("[data-timeline-booking-confirm]").hidden, false);
    await click("[data-timeline-booking-confirm]", {});
    const handoff = new URLSearchParams(location.href.split("?")[1]);
    assert.equal(handoff.get("package"), "short");
    assert.equal(handoff.get("room"), "r");
    assert.equal(handoff.get("time"), "13:00");
    assert.equal(handoff.get("fromTimeline"), "1");
    location.href = "";
    currentOptions = [];
    await click("[data-timeline-booking-confirm]", {});
    assert.equal(location.href, "", "Conflicting recheck must prevent navigation");
    assert.equal(element("[data-timeline-booking-confirm]").hidden, true);
    assert.equal(cells.filter((cell) => cell.dataset.selected === "true").length, 0);
    currentOptions = options;
    let resolveOld;
    deferred = new Promise((resolve) => { resolveOld = resolve; });
    const oldRequest = openHour();
    currentOptions = [];
    await openHour(14);
    resolveOld({ ok: true, json: async () => ({ packages: options }) });
    await oldRequest;
    assert.equal(element("[data-timeline-packages]").innerHTML, "", "Old responses must not replace the latest hour");
    currentOptions = [{ ...options[0], id: "night", start: new Date(base + 21 * hour).toISOString(), end: new Date(base + 33 * hour).toISOString(), time: "21:00" }];
    root.querySelector("[data-timeline-scroll]").scrollLeft = 350;
    element("[data-timeline-scroll]").scrollTop = 120;
    await openHour(21);
    await click("[data-timeline-package]", { dataset: { timelinePackage: "night" } });
    assert.equal(element("[data-timeline-scroll]").scrollLeft, 350);
    assert.equal(element("[data-timeline-scroll]").scrollTop, 120);
    assert.equal(cells.filter((cell) => cell.dataset.selected === "true").length, 12, "Overnight selection must expand and highlight the next day");
    await click("[data-timeline-booking-cancel]", {});
    assert.equal(element("[data-timeline-booking]").hidden, true);
    assert.equal(popupOpen, false);
    assert.equal(cells.filter((cell) => cell.dataset.selected === "true").length, 0);
    console.log("Timeline booking choices, highlights, handoff, revalidation, stale responses and overnight expansion: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
