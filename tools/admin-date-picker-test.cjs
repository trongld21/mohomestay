const assert = require("node:assert/strict");
const calendar = require("../public/assets/admin-date-picker.js");

assert.equal(calendar.parse("2028-02-29").toISOString(), "2028-02-29T12:00:00.000Z");
for (const value of ["2026-02-29", "2026-13-01", "2026-10-32", "22/10/2026", ""]) {
    assert.throws(() => calendar.parse(value), RangeError);
}
const days = calendar.monthDays(2026, 9);
assert.equal(days.length, 42);
assert.equal(days[0].value, "2026-09-28");
assert.equal(days[3].value, "2026-10-01");
assert.equal(days.filter((day) => !day.otherMonth).length, 31);
assert.equal(new Set(days.map((day) => day.value)).size, 42);
assert.equal(calendar.monthDays(2028, 1).filter((day) => !day.otherMonth).length, 29);
console.log("Admin calendar date picker tests: OK");
