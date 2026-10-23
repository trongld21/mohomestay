const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const source = fs.readFileSync(require.resolve("../public/assets/rooms.js"), "utf8");
for (const requested of ["lang", "mo", "missing"]) {
    const rooms = ["mo", "lang"].map((id) => ({
        id, name: id === "lang" ? "Lặng" : "Mơ", status: "ACTIVE",
        maxGuests: 2, tags: [], packages: [], image: "", subtitle: "",
    }));
    let displayedRoom = "Mơ";
    const roomSelect = {
        value: "mo",
        dispatchEvent(event) {
            if (event.type === "change") {
                displayedRoom = rooms.find((room) => room.id === this.value).name;
            }
        },
    };
    const node = () => ({ value: "", innerHTML: "", setAttribute() {} });
    const summary = node();
    const form = {
        elements: { roomId: roomSelect },
        date: node(), time: node(), packageId: node(), numberOfGuests: node(),
        querySelector: (selector) => selector === ".booking-submit" ? node() : null,
    };
    const nodes = {
        "[data-booking-form]": form,
        "[data-summary]": summary,
        "[data-booking-message]": node(),
        "[data-terms]": node(),
        "[data-slot-grid]": node(),
        "[data-duration-time]": node(),
    };
    const root = { querySelector: (selector) => nodes[selector] };
    const config = { rooms, today: "2026-10-23" };
    vm.runInNewContext(source, {
        window: {
            LANG_HOME: config,
            MoBookingSlots: { configuredSlots: () => [], slotWindow: () => null },
        },
        LANG_HOME: config,
        document: { querySelector: (selector) => selector === "#booking-app" ? root : null },
        location: { search: `?room=${requested}` },
        URLSearchParams, Event,
        fetch: () => new Promise(() => {}),
    });
    const expected = requested === "lang" ? "Lặng" : "Mơ";
    assert.equal(displayedRoom, expected, "The dropdown must show the requested room immediately.");
    assert.ok(summary.innerHTML.includes(`<h2>${expected}</h2>`));
}
console.log("Booking room selection tests: OK");
