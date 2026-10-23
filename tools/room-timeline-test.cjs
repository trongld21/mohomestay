const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const context = vm.createContext({
    window: {},
    document: { querySelectorAll: () => [] },
    Intl, Date,
});
vm.runInContext(fs.readFileSync("public/assets/room-timeline.js", "utf8"), context);
const { cellState, freeWindows } = context.window.MoRoomTimeline;
const hour = 3600000;
const base = Date.parse("2027-01-15T03:00:00Z");
const room = { active: true, intervals: [
    { start: new Date(base).toISOString(), end: new Date(base + 2 * hour).toISOString(), status: "CONFIRMED" },
    { start: new Date(base + 2 * hour).toISOString(), end: new Date(base + 3 * hour).toISOString(), status: "CLEANING" },
] };
assert.equal(cellState(room, base, base + hour, 0), "booked");
assert.equal(cellState(room, base + 2 * hour, base + 3 * hour, 0), "cleaning");
assert.equal(cellState(room, base + 3 * hour, base + 4 * hour, 0), "free");
assert.equal(cellState({ ...room, active: false }, base, base + hour, 0), "inactive");
const windows = freeWindows(room, base - 2 * hour, base + 4 * hour);
assert.equal(JSON.stringify(windows), JSON.stringify([
    [base - 2 * hour, base - hour],
    [base + 3 * hour, base + 4 * hour],
]));
const held = { active: true, intervals: [{ start: new Date(base).toISOString(), end: new Date(base + hour).toISOString(), status: "HELD" }] };
assert.equal(cellState(held, base, base + hour, 0), "held");
assert.equal(cellState(held, base + hour, base + 2 * hour, 0), "free");
console.log("Room timeline half-open intervals, cleanup and held-state tests: OK");
