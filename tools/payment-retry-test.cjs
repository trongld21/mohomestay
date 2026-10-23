const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const source = fs.readFileSync("public/assets/app.js", "utf8");

(async () => {
    for (const [status, paymentStatus, testing = false] of [
        ["CANCELLED", "UNPAID"], ["EXPIRED", "UNPAID"],
        ["CANCELLED", "PAID"], ["PENDING", "UNPAID"],
        ["PENDING", "PENDING", true],
    ]) {
        const out = { innerHTML: "" };
        const root = { dataset: { code: "MO-1234567890" }, querySelector: () => out };
        let stopped = false;
        let redirected = "";
        const booking = {
            status, paymentStatus, bookingCode: "MO-1234567890", room: "White",
            total: 150000, expires: new Date(Date.now() + 600000).toISOString(),
            checkoutUrl: "https://pay.payos.vn/web/old-link",
        };
        if (testing) booking.mockPayment = { orderCode: "1234567890123", amount: 150000 };
        const context = vm.createContext({
            document: {
                querySelector: (selector) => selector === "#payment-app" ? root : null,
                querySelectorAll: () => [],
            },
            location: { search: "", hash: "#" + "a".repeat(64), replace: (url) => { redirected = url; } },
            LANG_HOME: { csrf: "test-csrf" },
            sessionStorage: { getItem: () => null },
            setInterval: () => 1, clearInterval: () => { stopped = true; },
            URLSearchParams,
            fetch: async (url, options) => {
                if (url === "/api/dev/payments/callback") {
                    assert.equal(testing, true);
                    assert.equal(options.headers["X-CSRF-Token"], "test-csrf");
                    assert.deepEqual(JSON.parse(options.body), booking.mockPayment);
                    booking.status = "CONFIRMED";
                    booking.paymentStatus = "PAID";
                }
                return { ok: true, json: async () => booking };
            },
        });
        vm.runInContext(source, context);
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(out.innerHTML.includes("old-link"), status === "PENDING" && !testing);
        assert.equal(out.innerHTML.includes("data-simulate-payment"), testing);
        assert.equal(out.innerHTML.includes("Đặt lại phòng"), status !== "PENDING" && paymentStatus !== "PAID");
        assert.equal(stopped, status !== "PENDING");
        context.booking = booking;
        const lookup = vm.runInContext("renderLookup(booking)", context);
        assert.equal(lookup.includes("old-link"), status === "PENDING");
        if (testing) {
            const button = {};
            await out.onclick({ target: { closest: () => button } });
            assert.ok(redirected.startsWith("/booking-success/MO-1234567890#"));
        }
    }
    console.log("Payment retry UI tests: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
