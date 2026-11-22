"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");

const base = process.env.BRANCH_TEST_URL || "http://127.0.0.1:8097";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const output = path.resolve(__dirname, "../storage/branch-profile-qa.tmp/screenshots");
fs.mkdirSync(output, { recursive: true });

async function login(page, email = "branch-test@example.test", password = "branch-test-only") {
    await page.goto(base + "/auth/login");
    await page.locator("#login-email").fill(email);
    await page.locator("#login-password").fill(password);
    await Promise.all([
        page.waitForURL(/\/admin(?:\/no-access)?(?:#.*)?$/),
        page.locator('[data-login-form] button[type="submit"]').click(),
    ]);
}

(async () => {
    const browser = await chromium.launch({ channel: "chrome", headless: true });
    try {
        const context = await browser.newContext();
        await context.route("https://fonts.googleapis.com/**", (route) => route.fulfill({ contentType: "text/css", body: "" }));
        const page = await context.newPage();
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        await login(page);
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto(base + "/admin/branches");
            await page.getByRole("heading", { name: "Quản lý chi nhánh", exact: true }).waitFor();
            assert.equal(await page.getByRole("navigation", { name: "Danh sách chi nhánh" }).getByRole("link").count(), 2);
            if (width < 1280) await page.locator("[data-admin-menu-toggle]").click();
            const switcher = page.locator("[data-branch-switcher]");
            const trigger = switcher.locator("summary");
            const menu = switcher.getByRole("navigation", { name: "Chọn chi nhánh" });
            assert.equal(await switcher.getAttribute("open"), null);
            assert.equal(await menu.isVisible(), false);
            assert.equal(await page.locator("#admin-sidebar .branch-selector").count(), 0, "Old separate selector must be removed");
            await trigger.focus();
            await page.keyboard.press("Enter");
            await menu.waitFor({ state: "visible" });
            assert.equal(await menu.locator("[data-branch-switch]").count(), 2);
            await page.screenshot({ path: path.join(output, `dropdown-${width}.png`), fullPage: true });
            await page.keyboard.press("Escape");
            assert.equal(await switcher.getAttribute("open"), null);
            assert.equal(await trigger.evaluate((node) => node === document.activeElement), true);
            if (width < 1280) {
                assert.notEqual(await page.locator("#admin-sidebar").getAttribute("data-open"), null);
                await page.locator("[data-admin-menu-close]").click();
            } else {
                await trigger.click();
                await page.getByRole("heading", { name: "Quản lý chi nhánh", exact: true }).click();
                assert.equal(await switcher.getAttribute("open"), null);
            }
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.screenshot({ path: path.join(output, `management-${width}.png`), fullPage: true });
        }

        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(base + "/admin/branches?edit=2");
        const form = page.locator("[data-branch-profile-form]");
        assert.equal(await form.locator('[name="name"]').inputValue(), "Chi nhánh 2");
        const staleVersion = await form.locator('[name="version"]').inputValue();
        const csrf = await form.locator('[name="csrf"]').inputValue();
        const input = {
            id: "2", version: staleVersion, csrf,
            name: "Mơ Garden", address: "123 Đường Vườn Mơ, Cần Thơ", phone: "+84 901 234 567",
            email: "garden@example.test", map_url: "https://maps.google.com/?q=Can+Tho", description: "Không gian bên vườn.\nHẹn bạn ghé Mơ.",
        };
        for (const [name, value] of Object.entries(input)) {
            if (!["id", "version", "csrf"].includes(name)) await form.locator(`[name="${name}"]`).fill(value);
        }
        await Promise.all([
            page.waitForURL(/saved=1/),
            form.getByRole("button", { name: "Lưu thay đổi", exact: true }).click(),
        ]);
        await page.getByRole("status").filter({ hasText: "Đã lưu thông tin" }).waitFor();
        assert.equal(await page.locator('#branch-name').inputValue(), "Mơ Garden");
        assert.equal(await page.locator('#branch-address').inputValue(), input.address);
        const stale = await context.request.post(base + "/admin/branches", { form: { ...input, name: "Stale overwrite" } });
        assert.equal(stale.status(), 409);
        assert.ok((await stale.text()).includes("Thông tin đã được thay đổi"));
        const invalid = await context.request.post(base + "/admin/branches", { form: { ...input, version: String(Number(staleVersion) + 1), email: "bad-email" } });
        assert.equal(invalid.status(), 422);
        const noCsrf = await context.request.post(base + "/admin/branches", { form: { ...input, csrf: "wrong" } });
        assert.equal(noCsrf.status(), 419);
        await page.goto(base + "/admin/branches?edit=1");
        assert.equal(await page.locator('#branch-name').inputValue(), "Chi nhánh 1");
        assert.ok((await page.locator('#branch-address').inputValue()).includes("82 đường B18"));
        await page.locator("[data-branch-switcher] > summary").click();
        await page.locator('[data-branch-switch][href="/chi-nhanh/2/admin"]').click();
        await page.waitForURL(base + "/chi-nhanh/2/admin");
        assert.ok((await page.locator('[data-branch-switcher] > summary').innerText()).includes("Mơ Garden"));
        await page.goto(base + "/chi-nhanh/2/admin/accounts");
        assert.equal(await page.locator('select[name="branch_id"] option[value="2"]').first().innerText(), "Mơ Garden");

        // A forged staff request cannot update shared branch information.
        const newForm = page.locator('form:has(input[name="id"][value=""])');
        await newForm.locator('[name="name"]').fill("Staff profile test");
        await newForm.locator('[name="email"]').fill("profile-staff@example.test");
        await newForm.locator('[name="password"]').fill("profile-staff-test-123");
        await newForm.getByRole("combobox", { name: "Chi nhánh được quản lý" }).click();
        await newForm.getByRole("option", { name: "Mơ Garden", exact: true }).click();
        await Promise.all([page.waitForURL(/saved=1/), newForm.getByRole("button", { name: "Tạo tài khoản", exact: true }).click()]);
        const staffContext = await browser.newContext();
        await staffContext.route("https://fonts.googleapis.com/**", (route) => route.fulfill({ contentType: "text/css", body: "" }));
        const staff = await staffContext.newPage();
        await login(staff, "profile-staff@example.test", "profile-staff-test-123");
        assert.equal((await staffContext.request.get(base + "/chi-nhanh/2/admin/branches")).status(), 403);
        const staffCsrf = await staff.evaluate(() => window.LANG_HOME.csrf);
        assert.equal((await staffContext.request.post(base + "/chi-nhanh/2/admin/branches", { form: { ...input, csrf: staffCsrf, version: "2" } })).status(), 403);
        await staff.goto(base + "/chi-nhanh/2/admin/no-access");
        await staff.locator('[data-branch-switcher] > summary').click();
        assert.equal(await staff.locator('[data-branch-switcher] [data-branch-switch]').count(), 1);
        assert.equal(await staff.locator('[data-branch-switcher] .branch-switcher-manage').count(), 0);
        await staffContext.close();
        await page.goto(base + "/chi-nhanh/2/rooms");
        const footer = page.locator("#lien-he-footer");
        assert.ok((await footer.innerText()).includes(input.address));
        assert.equal(await footer.locator('a[href="mailto:garden@example.test"]').count(), 1);
        assert.equal(await footer.locator('a[href="tel:+84901234567"]').count(), 1);
        await page.goto(base + "/rooms");
        assert.ok((await page.locator("#lien-he-footer").innerText()).includes("82 đường B18"));
        assert.ok(!(await page.locator("#lien-he-footer").innerText()).includes(input.address));
        assert.deepEqual(errors, []);
        await context.close();
        console.log("Branch management desktop/mobile: compact disclosure, keyboard/outside close, scoped switching, persisted profiles, concurrency/validation, staff denial and public contact isolation OK");
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
