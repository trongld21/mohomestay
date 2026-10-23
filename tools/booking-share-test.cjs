const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const source = fs.readFileSync("public/assets/admin.js", "utf8");
const helper = source.slice(source.indexOf("    function bookingSuccessLink("), source.indexOf("    function renderBookingRow("));
const context = vm.createContext({ location: { origin: "http://localhost:8080" } });
vm.runInContext(helper, context);
const booking = { booking_code: "MO-0EAA7CAA85", access_token: "a".repeat(64), status: "CONFIRMED" };
assert.equal(context.bookingSuccessLink(booking), `http://localhost:8080/booking-success/MO-0EAA7CAA85#${booking.access_token}`);
for (const status of ["PENDING", "CANCELLED"]) {
    assert.equal(context.bookingSuccessLink({ ...booking, status }), "");
}
assert.equal(context.bookingSuccessLink({ ...booking, access_token: "" }), "");
assert.equal(context.bookingSuccessLink({ ...booking, booking_code: '<script>' }), "");
context.location.origin = "https://mohomestay.vn";
assert.ok(context.bookingSuccessLink({ ...booking, status: "CHECKED_IN" }).startsWith("https://mohomestay.vn/booking-success/"));
console.log("Booking success share link: token, confirmed states, validation and current origin: OK");
