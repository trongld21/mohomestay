"use strict";

// Render the production PHP view with fixture accounts; no database writes.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { spawnSync } = require("node:child_process");
const { chromium } = require("playwright");

const output = process.env.PERMISSION_ARTIFACT_DIR || path.join(os.tmpdir(), "mo-permissions-qa");
fs.mkdirSync(output, { recursive: true });
const accounts = [
    { id: "qa-one", name: "Lễ tân <kiểm thử>", email: "letan@example.test", is_active: 1, permissions: '["bookings.view","bookings.create"]' },
    { id: "qa-two", name: "Quản lý kho", email: "kho@example.test", is_active: 0, permissions: "[]" },
];

function render(items) {
    const code = `
        require "src/accounts.php";
        require "src/permission-view.php";
        function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); }
        function config($key) { return "admin@example.test"; }
        function csrf_token() { return "fixture-csrf"; }
        function asset_url($path) { return $path; }
        render_permission_manager(json_decode($argv[1], true), "");
    `;
    const result = spawnSync("php", ["-r", code, JSON.stringify(items)], {
        cwd: path.resolve(__dirname, ".."), encoding: "utf8",
    });
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
}

(async () => {
    const browser = await chromium.launch({ channel: process.env.PAYROLL_BROWSER_CHANNEL || "chrome", headless: true });
    try {
        const css = ["app.css", "tailwind.css"].map((file) => fs.readFileSync(path.resolve(__dirname, "../public/assets", file), "utf8")).join("\n");
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 950 } });
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));
            page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
            const html = render(accounts).replace(/<script defer[^>]*><\/script>/g, "");
            await page.route("**/*", (route) => route.request().isNavigationRequest()
                ? route.fulfill({ contentType: "text/html; charset=utf-8", body: `<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body class="admin-page"><main class="p-4 sm:p-8">${html}</main><div data-toast-region></div><dialog data-dialog-root class="ui-dialog-root"></dialog></body></html>` })
                : route.abort());
            await page.goto("http://localhost/admin/permissions");
            await page.addScriptTag({ path: path.resolve(__dirname, "../public/assets/ui.js") });
            await page.addScriptTag({ path: path.resolve(__dirname, "../public/assets/permissions.js") });
            const form = page.locator("[data-permission-form]");
            const checkbox = (key) => form.locator(`input[value="${key}"]`);
            assert.equal(await page.locator("[data-selected-name]").innerText(), accounts[0].name);
            assert.equal(await page.locator("kiểm").count(), 0, "Account names must be escaped as text.");
            assert.equal(await form.locator('[name="csrf"]').inputValue(), "fixture-csrf");
            assert.equal(await form.locator('[name="id"]').inputValue(), "qa-one");
            assert.ok(await page.locator("[data-permission-save]").isDisabled());
            assert.equal(await form.locator('input[value^="payroll"], input[value^="people-counter"]').count(), 0);
            await page.locator("[data-permission-search]").fill("thu tien");
            assert.ok(await checkbox("bookings.payment").isVisible());
            assert.equal(await checkbox("rooms.edit").isVisible(), false);
            await checkbox("bookings.payment").check();
            assert.ok(await checkbox("bookings.view").isChecked());
            assert.ok(await page.locator("[data-permission-reset]").isVisible());
            await page.locator('[data-select-account="qa-two"]').click();
            await page.getByRole("button", { name: "Tiếp tục chỉnh sửa", exact: true }).click();
            assert.equal(await form.locator('[name="id"]').inputValue(), "qa-one");
            assert.ok(await checkbox("bookings.payment").isChecked());
            await page.locator('[data-select-account="qa-two"]').click();
            await page.locator("[data-dialog-confirm]").click();
            assert.equal(await form.locator('[name="id"]').inputValue(), "qa-two");
            assert.equal(await page.locator("[data-selected-status]").innerText(), "Đã khóa đăng nhập");
            const rooms = page.locator('[data-permission-group="rooms"]');
            await rooms.locator("[data-group-select]").click();
            assert.equal(await rooms.locator("input:checked").count(), 6);
            await checkbox("rooms.view").uncheck();
            assert.equal(await rooms.locator("input:checked").count(), 0);
            await checkbox("rooms.edit").check();
            assert.ok(await checkbox("rooms.view").isChecked());
            await page.locator("[data-permission-reset]").click();
            assert.equal(await form.locator("input:checked").count(), 0);
            await page.locator("[data-account-search]").fill("khong co");
            assert.ok(await page.locator("[data-no-accounts]").isVisible());
            await page.locator("[data-account-search]").fill("");
            await page.locator('[data-select-account="qa-one"]').click();
            await page.locator("[data-permission-search]").fill("khong co");
            assert.ok(await page.locator("[data-no-permissions]").isVisible());
            await page.locator("[data-permission-search]").fill("");
            await checkbox("bookings.view").uncheck();
            assert.equal(await checkbox("bookings.create").isChecked(), false);
            await checkbox("bookings.overview").check();
            assert.equal(await checkbox("bookings.view").isChecked(), false, "Overview must remain independent of booking list access.");
            await page.locator("[data-permission-reset]").click();
            assert.ok(await checkbox("bookings.create").isChecked());
            await page.screenshot({ path: path.join(output, `permissions-${width}.png`), fullPage: true });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            await checkbox("bookings.payment").check();
            const payload = await form.evaluate((node) => Object.fromEntries([...new FormData(node)].filter(([key]) => key !== "permissions[]")));
            assert.equal(payload.id, "qa-one");
            const grants = await form.evaluate((node) => new FormData(node).getAll("permissions[]"));
            assert.deepEqual(grants.sort(), ["bookings.create", "bookings.payment", "bookings.view"]);
            // Verify the submit state while preventing any navigation or real write.
            await form.evaluate((node) => node.addEventListener("submit", (event) => event.preventDefault(), { once: true }));
            await page.locator("[data-permission-save]").click();
            assert.equal(await form.getAttribute("aria-busy"), "true");
            assert.ok(await page.locator("[data-permission-save]").isDisabled());
            assert.equal(await page.locator("[data-permission-save]").innerText(), "Đang lưu…");
            assert.deepEqual(errors, []);
            await page.close();
        }
        const empty = render([]);
        assert.ok(empty.includes("Chưa có tài khoản nhân viên") && !empty.includes("data-permission-form"));
        const untrusted = render([{ ...accounts[0], name: '</script><script>alert("x")</script>' }]);
        assert.equal(untrusted.includes('<script>alert("x")</script>'), false, "HTML and embedded JSON must escape account data.");
        console.log("Permissions desktop/mobile: filtering, dependencies, group selection, reset, unsaved confirmation, account targeting, root-only modules and empty state: OK");
        console.log("Screenshots: " + output);
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
