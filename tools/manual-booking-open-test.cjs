const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const picker = require("../public/assets/time-picker.js");

async function checkOpen(now, expectedDate, expectedTime) {
    const listeners = {};
    const nodes = new Map();
    const node = (key) => {
        if (!nodes.has(key)) nodes.set(key, {
            value: "", dataset: {}, classList: { remove() {}, add() {} },
            addEventListener() {}, querySelectorAll: () => [],
            querySelector: (selector) => node(selector),
        });
        return nodes.get(key);
    };
    const fields = new Proxy({}, { get: (_, key) => node(key) });
    const form = node("form");
    form.elements = fields;
    form.reset = () => {};
    const dialog = node("dialog");
    dialog.showModal = () => { dialog.open = true; };
    const trigger = node("[data-create-booking]");
    trigger.addEventListener = (name, handler) => { listeners[name] = handler; };
    const document = {
        querySelector: (selector) => selector === "[data-manual-booking-dialog]" ? dialog : node(selector),
        addEventListener() {}, body: node("body"),
    };
    const window = { addEventListener() {} };
    const NativeDate = Date;
    class Clock extends NativeDate {
        constructor(...args) { super(...(args.length ? args : [now])); }
        static now() { return new NativeDate(now).getTime(); }
    }
    global.document = document;
    global.window = window;
    try {
        vm.runInNewContext(fs.readFileSync("public/assets/manual-booking.js", "utf8"), {
            document, window, Date: Clock, Intl, crypto: require("node:crypto").webcrypto,
            MoTimePicker: picker,
            MoAdminDatePicker: { create: () => ({ refresh() {} }), parse: (date) => new Date(date) },
            MoBookingSlots: { configuredSlots: () => [] },
            fetch: async () => ({ ok: true, json: async () => ({ rooms: [] }) }),
        });
        await listeners.click();
        assert.equal(dialog.open, true, "Create booking must open the dialog at any minute");
        assert.equal(fields.date.value, expectedDate);
        assert.equal(fields.time.value, expectedTime);
    } finally {
        delete global.document;
        delete global.window;
    }
}

(async () => {
    await checkOpen("2026-11-01T17:19:12+07:00", "2026-11-01", "17:30");
    await checkOpen("2026-11-01T17:45:00+07:00", "2026-11-01", "18:00");
    await checkOpen("2026-11-01T23:59:00+07:00", "2026-11-02", "00:00");
    await checkOpen("2026-11-01T17:30:00+07:00", "2026-11-01", "17:30");
    console.log("Manual booking dialog opening: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
