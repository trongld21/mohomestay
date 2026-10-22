const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const source = fs.readFileSync("public/assets/admin.js", "utf8");
const actionSource = source.slice(source.indexOf("    async function bookingAction("), source.indexOf("    async function loadRooms("));

(async () => {
    for (const status of ["CHECKED_IN", "CHECKED_OUT", "CANCELLED"]) {
        for (const accepted of [false, true]) {
            const requests = [];
            const prompts = [];
            const button = { dataset: { id: "test-id", bookingAction: status }, disabled: false };
            const context = vm.createContext({
                bookings: [{ id: "test-id", booking_code: "MO-TEST", guest_name: "Khách thử", room_name: "Pink" }],
                LangUI: { confirm: async (options) => { prompts.push(options); return accepted; }, toast() {} },
                request: async (...args) => { requests.push(args); },
                mutation: (method, body) => ({ method, body }),
                loadBookings() {},
            });
            vm.runInContext(actionSource, context);
            await context.bookingAction({ target: { closest: (selector) => selector === "[data-booking-action]" ? button : null } });
            assert.equal(prompts.length, 1);
            assert.ok(prompts[0].message.includes("MO-TEST"));
            assert.equal(requests.length, accepted ? 1 : 0);
            if (!accepted) assert.equal(button.disabled, false);
            if (status === "CANCELLED") assert.equal(prompts[0].tone, "danger");
        }
    }
    console.log("Booking action confirmation tests: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
