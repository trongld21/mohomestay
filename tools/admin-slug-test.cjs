const assert = require("node:assert/strict");
const { normalize, conflict } = require("../public/assets/admin-slug.js");

assert.equal(normalize("  Phòng Mơ — Đậm 2  "), "phong-mo-dam-2");
assert.equal(normalize("PHÒNG__MÂY / 01"), "phong-may-01");
assert.equal(normalize("---"), "");
assert.equal(normalize("Mơ".normalize("NFD")), "mo");
const rooms = [
    { id: "a", slug: "phong-may" },
    { id: "b", slug: "phong-mo" },
];
assert.equal(conflict("phong-may", rooms, "a"), false);
assert.equal(conflict("phong-may", rooms, "b"), true);
assert.equal(conflict("phong-moi", rooms, null), false);
console.log("Admin room slug tests: OK");
