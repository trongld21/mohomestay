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
const exactListeners = {};
exact.addEventListener = (name, handler, options) => {
    exactListeners[name] = { handler, options };
};
const hourLabel = node();
const minuteLabel = node();
const fields = { arrivalHour: node(), arrivalMinute: node(), time: node() };
const root = {
    querySelectorAll: () => [],
    querySelector: (selector) => selector === "[data-time-hour-label]" ? hourLabel : minuteLabel,
};
const panelListeners = {};
const panel = {
    addEventListener(name, handler) { panelListeners[name] = handler; },
    classList: { remove() {} },
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
let wheelPrevented = false;
document.activeElement = exact;
exactListeners.wheel.handler({ preventDefault() { wheelPrevented = true; } });
assert.equal(wheelPrevented, true, "Wheel must not step the focused exact-minute input.");
assert.equal(exactListeners.wheel.options.passive, false);
assert.equal(exact.value, 54);
wheelPrevented = false;
document.activeElement = null;
exactListeners.wheel.handler({ preventDefault() { wheelPrevented = true; } });
assert.equal(wheelPrevented, false, "An unfocused input must allow normal scrolling.");

const calendarPicker = picker.create({
    addEventListener() {},
    querySelector() { throw new Error("Calendar picker must use its supplied fields, not assume a form exists."); },
}, { root, panel, fields });
calendarPicker.set("23:59");
assert.equal(fields.time.value, "23:59", "Calendar can reuse the picker without an admin form.");
assert.equal(hourLabel.textContent, "23 giờ");
assert.equal(minuteLabel.textContent, "59 phút");

const now = new Date("2026-10-24T12:19:30+07:00");
assert.equal(picker.minimumMinute("2026-10-24", now), 740);
assert.equal(picker.minimumMinute("2026-10-25", now), 0);
assert.equal(picker.minimumMinute("2026-10-23", now), 1440);
assert.equal(picker.minimumMinute("2026-10-24", new Date("2026-10-24T23:59:00+07:00")), 1440);
let bookingDate = "2026-10-24";
const bookingPicker = picker.create(dialog, { getDate: () => bookingDate, now: () => now });
bookingPicker.set("12:00");
const hourButton = (value) => hours.innerHTML.match(new RegExp(`<button[^>]*data-time-hour="${value}"[^>]*>`))[0];
const minuteButton = (value) => minutes.innerHTML.match(new RegExp(`<button[^>]*data-time-minute="${value}"[^>]*>`))[0];
assert.match(hourButton(0), /disabled/, "Midnight today must be unavailable at noon.");
assert.doesNotMatch(hourButton(12), /disabled/, "The current hour still has future minutes.");
assert.match(minuteButton(0), /disabled/);
assert.doesNotMatch(minuteButton(20), /disabled/);
assert.equal(exact.min, 20, "Exact-minute entry must use the same cutoff.");
assert.equal(exact.disabled, true, "A closed minute editor must not block booking form validation.");
panelListeners.toggle({ newState: "open" });
assert.equal(exact.disabled, false, "Opening the picker must enable precise minute editing.");
bookingPicker.setDisabled(true);
assert.equal(exact.disabled, true, "Disabling the picker must disable its minute editor.");
bookingPicker.setDisabled(false);
assert.equal(exact.disabled, true, "Re-enabling a closed picker must leave its draft editor disabled.");
panelListeners.toggle({ newState: "open" });
bookingPicker.close();
assert.equal(exact.disabled, true, "Closing the picker must exclude its draft from form validation.");
bookingDate = "2026-10-25";
bookingPicker.refresh();
assert.doesNotMatch(hourButton(0), /disabled/, "Tomorrow must allow midnight.");
assert.equal(exact.min, 0);
const halfHourPicker = picker.create(dialog, { minuteStep: 30 });
halfHourPicker.set('17:30');
assert.equal((minutes.innerHTML.match(/role="option"/g) || []).length, 2, 'Booking picker offers only 00 and 30 minutes');
assert.throws(() => halfHourPicker.set('17:15'), RangeError, 'Booking picker rejects odd minutes');
let minuteStep = 1;
const customPicker = picker.create(dialog, { minuteStep: () => minuteStep });
customPicker.set('17:09');
minuteStep = 30;
assert.throws(() => customPicker.set('17:09'), RangeError, 'Switching from actual immediate arrival back to a package restores half-hour choices');
delete global.document;
delete global.window;
console.log("24-hour time picker tests: OK");
