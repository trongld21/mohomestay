"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");

// Run only against the disposable local fixture, never the production website.
const base = process.env.BRANCH_TEST_URL || "http://127.0.0.1:8097";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const artifacts = path.resolve(__dirname, "../storage/branch-qa.tmp/screenshots");
fs.mkdirSync(artifacts, { recursive: true });

async function login(page) {
    await page.goto(base + "/auth/login");
    await page.locator("#login-email").fill("branch-test@example.test");
    await page.locator("#login-password").fill("branch-test-only");
    await Promise.all([
        page.waitForURL(/\/admin(?:#.*)?$/),
        page.locator('[data-login-form] button[type="submit"]').click(),
    ]);
}

async function api(page, url, body, method = body ? "POST" : "GET") {
    return page.evaluate(async ({ url, body, method }) => {
        const response = await fetch(url, {
            method,
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.LANG_HOME.csrf,
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        return { status: response.status, data: await response.json() };
    }, { url, body, method });
}

(async () => {
    const browser = await chromium.launch({ channel: "chrome", headless: true });
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 } });
            await context.route("https://fonts.googleapis.com/**", (route) => route.fulfill({ contentType: "text/css", body: "" }));
            const page = await context.newPage();
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));
            page.on("console", (message) => {
                if (message.type() === "error") {
                    errors.push(message.text());
                }
            });
            await page.goto(base + "/chi-nhanh/2/rooms");
            await page.getByRole("heading", { name: "Chưa có phòng để đặt" }).waitFor();
            const selector = page.getByRole("navigation", { name: "Chọn chi nhánh" });
            assert.equal(await selector.locator('[aria-current="true"]').textContent(), "Chi nhánh 2");
            assert.equal(await selector.locator('[aria-current="true"]').evaluate((node) => getComputedStyle(node).color), "rgb(255, 255, 255)");
            assert.equal(await page.locator("main a").getAttribute("href"), "/rooms");
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await selector.getByRole("link", { name: "Chi nhánh 1", exact: true }).focus();
            assert.notEqual(await selector.getByRole("link", { name: "Chi nhánh 1", exact: true }).evaluate((node) => getComputedStyle(node).outlineStyle), "none");
            await page.screenshot({ path: path.join(artifacts, `empty-${width}.png`), fullPage: true });
            await selector.getByRole("link", { name: "Chi nhánh 1", exact: true }).click();
            await page.waitForURL(base + "/rooms");
            assert.ok(await page.locator("[data-public-room-grid] a").count() > 0);
            await login(page);
            await page.goto(base + "/chi-nhanh/2/admin");
            await page.waitForFunction(() => window.LANG_HOME && window.moBranchUrl);
            assert.equal(await page.locator("body").getAttribute("class"), "admin-page");
            if (width < 1280) {
                await page.locator("[data-admin-menu-toggle]").click();
            }
            const adminSelector = page.getByRole("navigation", { name: "Chọn chi nhánh" });
            await page.locator("[data-branch-switcher] > summary").click();
            await adminSelector.waitFor({ state: "visible" });
            assert.ok(await adminSelector.isVisible());
            assert.equal(await adminSelector.locator('[aria-current="true"] .flex-1').innerText(), "Chi nhánh 2");
            await page.screenshot({ path: path.join(artifacts, `admin-${width}.png`), fullPage: true });
            await adminSelector.getByRole("link", { name: /Chi nhánh 1/ }).click();
            await page.waitForURL(base + "/admin");
            assert.deepEqual(errors, [], `JS errors at ${width}px`);
            await context.close();
        }

        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await context.route("https://fonts.googleapis.com/**", (route) => route.fulfill({ contentType: "text/css", body: "" }));
        const one = await context.newPage();
        await login(one);
        const two = await context.newPage();
        await two.goto(base + "/chi-nhanh/2/admin");
        const legacy = await api(one, "/api/admin/bookings");
        assert.equal(legacy.data.bookings.length, 1);
        assert.equal((await api(two, "/api/admin/bookings")).data.bookings.length, 0);
        assert.equal((await api(one, "/api/admin/rooms")).data.rooms.length, 3);
        assert.equal((await api(two, "/api/admin/rooms")).data.rooms.length, 0);

        const create = await api(two, "/api/admin/rooms", {
            name: "Phòng CN2", slug: "pink", status: "ACTIVE", tags: [],
            packages: [{ name: "3 giờ", mode: "DURATION", durationMinutes: 180, price: 350000, enabled: true }],
        });
        assert.equal(create.status, 201, JSON.stringify(create.data));
        const room = create.data.room;
        assert.equal(room.slug, "pink", "Slugs are independent across branches");
        assert.equal((await api(one, "/api/admin/rooms")).data.rooms.length, 3);
        assert.equal((await api(two, "/api/admin/rooms")).data.rooms.length, 1);

        const booking = await api(two, "/api/admin/bookings", {
            roomId: room.id, packageId: room.packages[0].id,
            date: "2026-11-21", time: "10:00", numberOfGuests: 2,
            guestName: "Khách CN2", guestPhone: "0901234567", bookingSource: "PHONE",
            requestId: "b".repeat(32), paymentStatus: "PAID", paymentMethod: "cash",
            guestCheckout: true,
        });
        assert.equal(booking.status, 201, JSON.stringify(booking.data));
        assert.ok(booking.data.guestUrl.startsWith("/chi-nhanh/2/payment?"));
        const guest = await context.newPage();
        await guest.goto(base + booking.data.guestUrl);
        await guest.waitForURL(/\/chi-nhanh\/2\/booking-success\//);
        await guest.getByRole("heading", { name: "Phòng CN2", exact: true }).waitFor();
        assert.ok((await guest.locator("main").textContent()).includes("Phòng CN2"));
        await guest.close();
        assert.equal((await api(two, "/api/admin/bookings")).data.bookings.length, 1);
        assert.equal((await api(one, "/api/admin/bookings")).data.bookings.length, 1);
        const availability = await api(two, "/api/availability?date=2026-11-21&days=1");
        assert.equal(availability.status, 200);
        assert.equal(availability.data.rooms.length, 1);
        assert.equal(availability.data.rooms[0].id, room.id);

        const product = await api(two, "/api/admin/products", { name: "Nước CN2", price: 20000, trackStock: false });
        assert.equal(product.status, 200, JSON.stringify(product.data));
        assert.equal((await api(one, "/api/admin/products")).data.products.length, 0);
        const sale = await api(two, "/api/admin/sales", {
            requestId: "c".repeat(32), items: [{ productId: product.data.product.id, quantity: 2 }],
        });
        assert.equal(sale.status, 200, JSON.stringify(sale.data));
        const paid = await api(two, `/api/admin/sales/${sale.data.sale.id}/complete`, { note: "Đã nhận chuyển khoản thử nghiệm" });
        assert.equal(paid.status, 200, JSON.stringify(paid.data));
        assert.equal(paid.data.sale.status, "PAID");
        assert.equal((await api(one, `/api/admin/sales/${sale.data.sale.id}`)).status, 404);

        const expense = await api(two, "/api/admin/expenses", {
            requestId: "d".repeat(32), category: "Chi CN2", amount: 10000,
            spentAt: "2026-11-21T10:00", includeCashflow: true,
        });
        assert.equal(expense.status, 201, JSON.stringify(expense.data));
        assert.equal((await api(one, "/api/admin/expenses?from=2026-11-21&to=2026-11-21")).data.count, 0);
        const door = await api(two, "/api/admin/door-codes", {
            code: "654321", period: "DAY", status: "ACTIVE", startsAt: "2026-11-21T00:00", endsAt: "2026-11-22T00:00",
        });
        assert.equal(door.status, 201, JSON.stringify(door.data));
        const branchOneCodes = await api(one, "/api/admin/door-codes");
        assert.ok(!JSON.stringify(branchOneCodes.data).includes("654321"));
        await two.goto(base + "/chi-nhanh/2/admin/overview?from=2026-11-21&to=2026-11-21");
        await two.locator("[data-overview]").waitFor();
        assert.ok((await two.locator("main").textContent()).includes("350.000"));
        await two.screenshot({ path: path.join(artifacts, "overview-branch2.png"), fullPage: true });
        await context.close();
        console.log("Branches: desktop/mobile, keyboard, empty state, shared login, parallel tabs, room/booking/bill/expense/PIN and revenue isolation OK");
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
