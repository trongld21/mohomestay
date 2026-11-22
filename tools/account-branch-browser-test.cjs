"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");

const base = process.env.BRANCH_TEST_URL || "http://127.0.0.1:8097";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const artifacts = path.resolve(__dirname, "../storage/account-branch-qa.tmp/screenshots");
fs.mkdirSync(artifacts, { recursive: true });
const password = "staff-branch-test-123";

async function newContext(browser, width = 1440) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    await context.route("https://fonts.googleapis.com/**", (route) => route.fulfill({ contentType: "text/css", body: "" }));
    return context;
}

async function login(page, email, secret, loginPath = "/auth/login") {
    await page.goto(base + loginPath);
    await page.locator("#login-email").fill(email);
    await page.locator("#login-password").fill(secret);
    await Promise.all([
        page.waitForURL(/\/admin(?:#.*)?$/),
        page.locator('[data-login-form] button[type="submit"]').click(),
    ]);
}

async function chooseBranch(form, branch) {
    await form.getByRole("combobox", { name: "Chi nhánh được quản lý", exact: true }).click();
    await form.getByRole("option", { name: `Chi nhánh ${branch}`, exact: true }).click();
}

async function saveForm(page, form, label) {
    await Promise.all([
        page.waitForURL(/\?saved=1/),
        form.getByRole("button", { name: label, exact: true }).click(),
    ]);
    await page.getByRole("status").filter({ hasText: "Đã lưu" }).waitFor();
}

async function createStaff(page, branch) {
    await page.goto(base + "/admin/accounts");
    const form = page.locator('form:has(input[name="id"][value=""])');
    await form.locator('[name="name"]').fill(`Nhân viên CN${branch}`);
    await form.locator('[name="email"]').fill(`staff${branch}@example.test`);
    await form.locator('[name="password"]').fill(password);
    await chooseBranch(form, branch);
    await saveForm(page, form, "Tạo tài khoản");
    const card = page.locator("section").filter({ has: page.getByRole("heading", { name: `Nhân viên CN${branch}`, exact: true }) });
    const id = await card.locator('[name="id"]').inputValue();
    assert.equal(await card.locator('[name="branch_id"]').inputValue(), String(branch));
    await page.goto(base + "/admin/permissions?account=" + id);
    for (const permission of ["rooms.view", "rooms.create", "bookings.view", "bookings.overview", "expenses.view"]) {
        await page.locator(`input[value="${permission}"]`).check();
    }
    assert.equal(await page.locator("[data-selected-branch]").innerText(), `Chi nhánh ${branch}`);
    await saveForm(page, page.locator("[data-permission-form]"), "Lưu quyền");
    return id;
}

async function assertStaffScope(browser, branch, width) {
    const context = await newContext(browser, width);
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    // Log in through the other branch's page; landing must follow assignment.
    await login(page, `staff${branch}@example.test`, password, branch === 1 ? "/chi-nhanh/2/auth/login" : "/auth/login");
    assert.ok(page.url().startsWith(`${base}/chi-nhanh/${branch}/admin`));
    if (width < 1280) {
        await page.locator("[data-admin-menu-toggle]").click();
    }
    const selector = page.getByRole("navigation", { name: "Chọn chi nhánh" });
    await page.locator("[data-branch-switcher] > summary").click();
    await selector.waitFor({ state: "visible" });
    assert.equal(await selector.getByRole("link").count(), 1);
    assert.equal(await selector.locator("[data-branch-switch] .flex-1").innerText(), `Chi nhánh ${branch}`);
    await page.screenshot({ path: path.join(artifacts, `staff${branch}-${width}.png`), fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);

    const own = branch === 1 ? "" : "/chi-nhanh/2";
    const other = branch === 1 ? "/chi-nhanh/2" : "";
    const ownRooms = await context.request.get(base + own + "/api/admin/rooms");
    assert.equal(ownRooms.status(), 200);
    assert.equal((await ownRooms.json()).rooms.length, branch === 1 ? 3 : 0);
    const csrf = await page.evaluate(() => window.LANG_HOME.csrf);
    for (const endpoint of ["rooms", "bookings", "booking-overview", "expenses", "door-codes"]) {
        const denied = await context.request.get(base + other + "/api/admin/" + endpoint);
        assert.equal(denied.status(), 403, endpoint);
        assert.match((await denied.json()).error, /chi nhánh/);
    }
    const deniedWrite = await context.request.post(base + other + "/api/admin/rooms", {
        headers: { "X-CSRF-Token": csrf },
        data: { name: "Forbidden room", slug: "forbidden-room", packages: [] },
    });
    assert.equal(deniedWrite.status(), 403);
    assert.match((await deniedWrite.json()).error, /chi nhánh/);
    await page.goto(base + other + "/admin/overview");
    assert.ok(page.url().startsWith(`${base}/chi-nhanh/${branch}/admin`), "Wrong branch page must redirect without rendering its data");
    const accountPage = await context.request.get(base + own + "/admin/accounts");
    assert.equal(accountPage.status(), 403, "Only Super Admin can manage accounts");
    const selfEscalation = await context.request.post(base + own + "/admin/accounts", {
        form: { csrf, branch_id: branch === 1 ? "2" : "1", name: "Forged assignment" },
    });
    assert.equal(selfEscalation.status(), 403);
    assert.deepEqual(errors, []);
    return { context, page, csrf };
}

(async () => {
    const browser = await chromium.launch({ channel: "chrome", headless: true });
    try {
        const rootContext = await newContext(browser);
        const root = await rootContext.newPage();
        await login(root, "branch-test@example.test", "branch-test-only");
        const staffOne = await createStaff(root, 1);
        const staffTwo = await createStaff(root, 2);
        for (const width of [1440, 390]) {
            await root.setViewportSize({ width, height: 1000 });
            await root.goto(base + "/admin/accounts");
            assert.equal(await root.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await root.screenshot({ path: path.join(artifacts, `accounts-${width}.png`), fullPage: true });
            for (const branch of [1, 2]) {
                const staff = await assertStaffScope(browser, branch, width);
                await staff.context.close();
            }
        }
        const activeStaff = await assertStaffScope(browser, 2, 1440);
        await root.goto(base + "/admin/accounts");
        const form = root.locator(`form:has(input[name="id"][value="${staffTwo}"])`);
        await chooseBranch(form, 1);
        await saveForm(root, form, "Lưu tài khoản");
        const expired = await activeStaff.context.request.get(base + "/chi-nhanh/2/api/admin/rooms");
        assert.equal(expired.status(), 401, "Changing assignment must revoke the old session");
        await login(activeStaff.page, "staff2@example.test", password, "/chi-nhanh/2/auth/login");
        assert.ok(activeStaff.page.url().startsWith(base + "/chi-nhanh/1/admin"));
        assert.equal((await activeStaff.context.request.get(base + "/chi-nhanh/2/api/admin/rooms")).status(), 403);
        assert.equal((await activeStaff.context.request.get(base + "/api/admin/rooms")).status(), 200);
        await activeStaff.context.close();
        await root.setViewportSize({ width: 1440, height: 1000 });
        await root.goto(base + "/chi-nhanh/2/admin");
        await root.locator("[data-branch-switcher] > summary").click();
        assert.equal(await root.getByRole("navigation", { name: "Chọn chi nhánh" }).locator("[data-branch-switch]").count(), 2);
        assert.equal((await rootContext.request.get(base + "/api/admin/rooms")).status(), 200);
        assert.equal((await rootContext.request.get(base + "/chi-nhanh/2/api/admin/rooms")).status(), 200);
        await root.goto(base + "/admin/accounts");
        assert.equal(await root.locator(`form:has(input[name="id"][value="${staffOne}"]) [name="branch_id"]`).inputValue(), "1");
        await rootContext.close();
        console.log("Staff branch assignment: desktop/mobile UI, login routing, read/write denial, no self-assignment, root access and session revocation OK");
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
