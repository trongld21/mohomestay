const assert = require("node:assert/strict");
const picker = require("../public/assets/time-picker.js");

assert.deepEqual(picker.parse("00:00"), [0, 0]);
assert.deepEqual(picker.parse("20:54"), [20, 54]);
assert.deepEqual(picker.parse("23:59"), [23, 59]);
for (const invalid of ["24:00", "12:60", "8:15", "08:15 CH", "", "-1:00"]) {
    assert.throws(() => picker.parse(invalid), RangeError);
}
assert.deepEqual(picker.minuteOptions(54), [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 54, 55]);
assert.equal(picker.minuteOptions(30).length, 12);
assert.equal(picker.minuteOptions(59).at(-1), 59);
const node = () => ({ innerHTML: "", value: "", textContent: "", addEventListener() {} });
const hours = node();
const minutes = node();
const exact = node();
const hourLabel = node();
const minuteLabel = node();
const fields = { arrivalHour: node(), arrivalMinute: node(), time: node() };
const root = {
    querySelectorAll: () => [],
    querySelector: (selector) => selector === "[data-time-hour-label]" ? hourLabel : minuteLabel,
};
const panel = {
    addEventListener() {},
    querySelector: (selector) => ({ "[data-time-hours]": hours, "[data-time-minutes]": minutes, "[data-time-exact]": exact })[selector],
};
const dialog = {
    addEventListener() {},
    querySelector: (selector) => selector === ".manual-time-picker" ? root : selector === "form" ? { elements: fields } : panel,
};
global.document = { addEventListener() {} };
global.window = { addEventListener() {} };
const instance = picker.create(dialog);
assert.equal((hours.innerHTML.match(/role="option"/g) || []).length, 24, "Opening an unset picker must already have all hour options.");
assert.equal((minutes.innerHTML.match(/role="option"/g) || []).length, 12, "Quick minute options must exist before selecting a time.");
assert.equal(fields.time.value, "", "Rendering options must not silently choose a booking time.");
instance.set("20:54");
assert.equal(fields.time.value, "20:54");
assert.ok(minutes.innerHTML.includes('data-time-minute="54"'));
delete global.document;
delete global.window;
console.log("24-hour time picker tests: OK");
