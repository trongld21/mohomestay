"use strict";

// Local UI regression with mocked payroll responses; no employee records are written.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawnSync } = require("node:child_process");
const { chromium } = require("playwright");

const base = process.env.PAYROLL_TEST_URL || "http://127.0.0.1:8098";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const output = process.env.PAYROLL_ARTIFACT_DIR || path.join(os.tmpdir(), "mo-personnel-form-qa");
fs.mkdirSync(output, { recursive: true });
const employee = {
    id: "qa-existing", version: 7, code: "NV-QA", name: "Nhân viên kiểm thử",
    role: "Lễ tân", phone: "", email: "", start_date: "2026-10-25",
    end_date: null, status: "active", salary_type: "hourly", hourly_rate: 15000,
    monthly_salary: 0, expected_days: 26, note: "",
};

(async () => {
    const session = spawnSync("php", ["-r", 'require "src/bootstrap.php"; start_app_session(); $_SESSION=["admin_until"=>time()+600]; echo session_id(); session_write_close();'], {
        cwd: path.resolve(__dirname, ".."), encoding: "utf8",
    });
    assert.equal(session.status, 0, session.stderr);
    const sessionId = session.stdout.trim();
    const browser = await chromium.launch({ headless: true, channel: process.env.PAYROLL_BROWSER_CHANNEL || "chrome" });
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            await context.addCookies([{ name: "langhome_session", value: sessionId, url: base }]);
            const page = await context.newPage();
            const errors = [];
            const requests = [];
            page.on("pageerror", (error) => errors.push(error.message));
            page.on("console", (message) => {
                if (message.type() === "error" && !message.text().includes("422")) errors.push(message.text());
            });
            await page.route("**/api/admin/payroll**", async (route) => {
                const payload = route.request().postDataJSON();
                if (!payload) {
                    await route.fulfill({ json: { employees: [employee] } });
                    return;
                }
                requests.push(payload);
                await route.fulfill(requests.length === 1
                    ? { status: 422, json: { error: "Không lưu được hồ sơ kiểm thử. Thử lại." } }
                    : { json: { employee: { ...payload, id: "qa-new", code: "NV-NEW" } } });
            });
            await page.goto(base + "/admin/personnel");
            const form = page.locator("[data-create-form]");
            const add = page.getByRole("button", { name: "Thêm nhân viên", exact: true });
            const edit = page.getByRole("button", { name: "Sửa hồ sơ " + employee.name, exact: true });
            for (const closeMethod of ["cancel", "escape", "close"]) {
                await edit.click();
                assert.equal(await page.locator('dialog[data-create]').evaluate((dialog) => dialog.matches(':modal')), true);
                assert.equal(await form.locator('[name="email"], [name="start_date"], [name="end_date"]').count(), 0);
                assert.equal(await form.locator('[name="id"]').inputValue(), employee.id);
                assert.equal(await form.locator('[name="version"]').inputValue(), String(employee.version));
                if (closeMethod === "escape") await page.keyboard.press("Escape");
                else if (closeMethod === "close") await page.getByRole("button", { name: "Đóng hồ sơ nhân viên" }).click();
                else await form.getByRole("button", { name: "Hủy", exact: true }).click();
                await page.locator("[data-create]").waitFor({ state: "hidden" });
                assert.equal(await edit.evaluate((button) => button === document.activeElement), true);
                await add.click();
                await page.getByRole("heading", { name: "Thêm nhân viên", exact: true }).waitFor();
                assert.equal(await form.locator('[name="id"]').inputValue(), "", "Adding after editing must clear the existing employee ID.");
                assert.equal(await form.locator('[name="version"]').inputValue(), "", "Adding must clear the existing version.");
                assert.equal(await form.getByLabel("Họ và tên").inputValue(), "");
                assert.equal(await form.getByLabel("Đơn giá giờ (đ)").inputValue(), "0");
                assert.equal(await form.getByLabel("Ngày công chuẩn").inputValue(), "30");
                await form.getByRole("button", { name: "Hủy", exact: true }).click();
            }
            await add.click();
            await form.getByLabel("Họ và tên").fill("Nhân viên mới kiểm thử");
            await form.getByLabel("Đơn giá giờ (đ)").fill("15000");
            await page.keyboard.press("Escape");
            await page.locator("[data-confirm-cancel]").click();
            assert.ok(await page.locator("[data-create]").isVisible());
            await page.screenshot({ path: path.join(output, `personnel-form-${width}.png`), fullPage: true });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            await form.getByRole("button", { name: "Lưu hồ sơ" }).click();
            await page.getByText("Không lưu được hồ sơ kiểm thử. Thử lại.", { exact: true }).waitFor();
            assert.equal(await form.getByLabel("Họ và tên").inputValue(), "Nhân viên mới kiểm thử");
            assert.ok(await page.locator("[data-create]").isVisible());
            await form.getByRole("button", { name: "Lưu hồ sơ" }).click();
            await page.locator("[data-create]").waitFor({ state: "hidden" });
            await page.locator("[data-payroll][aria-busy]").waitFor({ state: "detached" });
            assert.equal(await add.evaluate((button) => button === document.activeElement), true);
            assert.equal(requests.length, 2);
            for (const payload of requests) {
                assert.equal(payload.action, "employee/save");
                assert.equal(payload.id, "", "A new employee must never update the previously edited record.");
                assert.equal(payload.version, 0);
                for (const key of ["email", "start_date", "end_date"]) assert.equal(key in payload, false);
            }
            await edit.click();
            await form.getByLabel("Điện thoại", { exact: true }).fill("0900000000");
            await form.getByRole("button", { name: "Lưu hồ sơ" }).click();
            await page.locator("[data-confirm-accept]").click();
            await page.locator("[data-create]").waitFor({ state: "hidden" });
            await page.locator("[data-payroll][aria-busy]").waitFor({ state: "detached" });
            assert.equal(requests[2].id, employee.id);
            assert.equal(requests[2].version, employee.version);
            assert.equal(await add.evaluate((button) => button === document.activeElement), true);
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log("Personnel form: edit → add, cancel → add, clean identity, error recovery, desktop/mobile and console: OK");
        console.log("Screenshots: " + output);
    } finally {
        await browser.close();
        fs.rmSync(path.resolve(__dirname, "../storage/sessions/sess_" + sessionId), { force: true });
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
