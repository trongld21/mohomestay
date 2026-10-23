const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

(async () => {
    for (const testing of [true, false]) {
        const node = () => ({ value: "", innerHTML: "", setAttribute() {}, dispatchEvent() {} });
        const checkbox = { checked: testing, addEventListener() {} };
        const submit = node();
        const fields = { roomId: { ...node(), value: "pink" } };
        const form = {
            elements: fields, date: node(), time: node(), packageId: node(), numberOfGuests: node(),
            querySelector: (selector) => selector === "[data-test-payment]" ? checkbox : submit,
        };
        const slot = { id: "3h", name: "Gói 3 giờ", mode: "DURATION", durationMinutes: 180, price: 200000 };
        const slotButton = { dataset: { slotId: "3h" } };
        const grid = { ...node(), querySelector: () => slotButton };
        const nodes = {
            "[data-booking-form]": form, "[data-summary]": node(),
            "[data-booking-message]": node(), "[data-terms]": node(),
            "[data-slot-grid]": grid, "[data-duration-time]": node(),
        };
        const root = { querySelector: (selector) => nodes[selector] };
        const config = {
            csrf: "csrf-test", today: "2099-01-01",
            rooms: [{ id: "pink", name: "Pink", status: "ACTIVE", maxGuests: 2, tags: [], packages: [slot] }],
        };
        const submissions = [];
        const location = { search: "?room=pink&time=14:00", href: "" };
        const context = vm.createContext({
            window: {
                LANG_HOME: config,
                MoBookingSlots: {
                    configuredSlots: () => [slot],
                    slotWindow: () => ({ start: new Date("2099-01-01T07:00:00Z"), end: new Date("2099-01-01T10:00:00Z") }),
                    slotState: () => "available",
                },
            },
            LANG_HOME: config, location,
            document: { querySelector: (selector) => selector === "#booking-app" ? root : null },
            URLSearchParams, Event, CSS: { escape: (value) => value },
            crypto: { randomUUID: () => "12345678-1234-1234-1234-123456789012" },
            sessionStorage: { setItem() {} }, LangUI: { toast() {} },
            FormData: class {
                *[Symbol.iterator]() {
                    yield* Object.entries({
                        roomId: "pink", packageId: "3h", date: "2099-01-01", time: "14:00",
                        guestName: "Khách thử", guestPhone: "0900000000", guestEmail: "test@example.com",
                        numberOfGuests: "2", acceptTerms: "on",
                    });
                }
            },
            fetch: async (url, options) => {
                if (url.startsWith("/api/availability")) {
                    return { ok: true, json: async () => ({ onlineBooking: true, rooms: [{ id: "pink", active: true, intervals: [] }] }) };
                }
                submissions.push({ url, options });
                return { ok: true, json: async () => ({
                    paymentUrl: "/payment?code=MO-1234567890#mock-token",
                    bookingCode: "MO-1234567890", orderCode: "1234567890123", token: "real-token",
                }) };
            },
        });
        vm.runInContext(fs.readFileSync("public/assets/rooms.js", "utf8"), context);
        await new Promise((resolve) => setImmediate(resolve));
        grid.onclick({ target: { closest: () => slotButton } });
        await form.onsubmit({ preventDefault() {} });
        const request = submissions[0];
        assert.equal(request.url, testing ? "/api/dev/payments/create" : "/api/bookings");
        assert.equal(request.options.headers["Content-Type"], "application/json");
        const body = JSON.parse(request.options.body);
        if (testing) {
            assert.equal(request.options.headers["X-CSRF-Token"], "csrf-test");
            assert.match(body.requestId, /^[a-f0-9]{32}$/);
            assert.equal(location.href, "/payment?code=MO-1234567890#mock-token");
        } else {
            assert.equal(body.requestId, undefined);
            assert.equal(location.href, "/payment?code=MO-1234567890#real-token");
        }
    }
    console.log("Customer booking real/mock routing tests: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
