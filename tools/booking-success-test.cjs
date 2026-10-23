const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const source = fs.readFileSync("public/assets/booking-success.js", "utf8");

(async () => {
    for (const status of ["CONFIRMED", "CHECKED_IN", "CHECKED_OUT", "PENDING", "CANCELLED", "EXPIRED", "NO_TOKEN"]) {
        const elements = Object.fromEntries(["content", "title", "intro"].map((name) => [name, {
            innerHTML: "", textContent: "", setAttribute() {}, querySelector: () => ({}),
        }]));
        const root = {
            dataset: { code: "MO-1234567890" },
            querySelector: (selector) => selector === "[data-success-mark]" ? {} : elements[selector.match(/success-(content|title|intro)/)[1]],
        };
        let fetched = 0;
        const context = vm.createContext({
            URL,
            document: { querySelector: () => root },
            location: { hash: status === "NO_TOKEN" ? "" : "#" + "a".repeat(64), pathname: "/booking-success/MO-1234567890" },
            history: { replaceState() {} },
            sessionStorage: { getItem: () => null, setItem() {} },
            fetch: async () => {
                fetched++;
                return { ok: true, json: async () => ({
                    status, room: "<script>bad()</script>", bookingCode: "MO-1234567890",
                    checkIn: "2026-10-23T13:00:00Z", checkOut: "2026-10-23T16:00:00Z",
                    total: 200000, paymentStatus: "PAID", emailStatus: "SENT",
                    roomImage: '/uploads/room.jpg', packageName: '3 giờ', guests: 2,
                    arrival: { isTest: true, guideUrl: 'javascript:bad()', rulesUrl: 'https://example.com/rules',
                        rules: '<script>rules()</script>', wifiName: 'mo home', wifiPassword: 'fixture-password',
                        doorCodes: [{ code: '<real-test-code>', startsAt: '2026-10-23T13:00:00Z', endsAt: '2026-10-23T16:00:00Z' }] },
                }) };
            },
        });
        vm.runInContext(source, context);
        await new Promise((resolve) => setImmediate(resolve));
        const confirmed = ["CONFIRMED", "CHECKED_IN", "CHECKED_OUT"].includes(status);
        assert.equal(elements.title.textContent === "Hẹn gặp bạn tại Mơ!", confirmed);
        if (confirmed) {
            assert.ok(elements.content.innerHTML.includes("&lt;script&gt;"));
            assert.ok(!elements.content.innerHTML.includes("<script>bad()"));
            assert.ok(elements.content.innerHTML.includes('src="/uploads/room.jpg"'));
            assert.ok(elements.content.innerHTML.includes('Hướng dẫn tự check-in'));
            assert.ok(elements.content.innerHTML.includes('Wi-Fi tại Mơ'));
            assert.ok(elements.content.innerHTML.includes('ĐƠN THỬ'));
            assert.ok(elements.content.innerHTML.includes('&lt;script&gt;rules()'));
            assert.ok(!elements.content.innerHTML.includes('href="javascript:'));
            assert.ok(elements.content.innerHTML.includes('https://example.com/rules'));
            assert.ok(elements.content.innerHTML.includes('mo home'));
            assert.ok(elements.content.innerHTML.includes('fixture-password'));
            assert.ok(!elements.content.innerHTML.includes('Bạn có thể lưu mã đơn'));
            assert.ok(!elements.content.innerHTML.includes('booking-success-email'));
            assert.ok(elements.content.innerHTML.includes('&lt;real-test-code&gt;'));
            assert.ok(!elements.content.innerHTML.includes('KHÔNG CẤP MÃ CỔNG'));
        }
        if (status === "NO_TOKEN") assert.equal(fetched, 0);
    }
    console.log("Booking success state and access tests: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
