"use strict";

// Production page with mocked payroll API responses; no employee data is written.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { spawnSync } = require("node:child_process");
const { chromium } = require("playwright");

const base = process.env.PAYROLL_TEST_URL || "http://127.0.0.1:8098";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const output = process.env.PAYROLL_ARTIFACT_DIR || path.join(os.tmpdir(), "mo-payroll-rate-qa");
fs.mkdirSync(output, { recursive: true });

(async () => {
    const session = spawnSync("php", ["-r", 'require "src/bootstrap.php"; start_app_session(); $_SESSION=["admin_until"=>time()+600]; echo session_id(); session_write_close();'], {
        cwd: path.resolve(__dirname, ".."), encoding: "utf8",
    });
    assert.equal(session.status, 0, session.stderr);
    const sessionId = session.stdout.trim();
    const browser = await chromium.launch({ channel: "chrome", headless: true });
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 950 } });
            await context.addCookies([{ name: "langhome_session", value: sessionId, url: base }]);
            const page = await context.newPage();
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));
            page.on("console", (message) => {
                if (message.type() === "error" && !message.text().includes("409")) errors.push(message.text());
            });
            const employee = {
                id: "qa-rate", version: 1, code: "NV-QA", name: "Nhân viên kiểm thử", role: "Lễ tân",
                status: "active", salary_type: "hourly", hourly_rate: 15000, start_date: "2026-10-01", end_date: null,
            };
            const snapshot = {
                period: { id: "qa-period", version: 2, name: "Kỳ lương kiểm thử", start_date: "2026-10-01", end_date: "2026-10-31", status: "draft" },
                entries: [{
                    employee_id: employee.id, employee_name: employee.name, employee_code: employee.code, role: employee.role,
                    minutes: 120, working_days: 2, base_salary: 30000, net_salary: 30000,
                    allowances: 0, deductions: 0,
                    details: {
                        salary_type: "hourly", hourly_rate: 15000, expected_days: 30, allowances: [], deductions: [],
                        shifts: [{ date: "2026-10-01", in: "08:00", out: "09:00" }, { date: "2026-10-02", in: "08:00", out: "09:00" }],
                    },
                }],
            };
            const writes = [];
            let fail = false;
            await page.route("**/api/admin/payroll**", async (route) => {
                if (route.request().method() === "GET") {
                    await route.fulfill({ json: new URL(route.request().url()).searchParams.get("resource") === "employees" ? { employees: [employee] } : snapshot });
                    return;
                }
                const payload = route.request().postDataJSON();
                writes.push(payload);
                if (fail) {
                    await route.fulfill({ status: 409, json: { error: "Hồ sơ đã thay đổi. Tải lại trước khi đổi đơn giá." } });
                    return;
                }
                assert.equal(payload.action, "entry/rate");
                assert.equal(payload.version, snapshot.period.version);
                assert.equal(payload.employee_version, employee.version);
                employee.hourly_rate = payload.hourly_rate;
                employee.version++;
                snapshot.period.version++;
                snapshot.entries[0].details.hourly_rate = payload.hourly_rate;
                snapshot.entries[0].base_salary = payload.hourly_rate * 2;
                snapshot.entries[0].net_salary = payload.hourly_rate * 2;
                await route.fulfill({ json: snapshot });
            });
            await page.goto(base + "/admin/payroll?period=qa-period");
            const rate = page.locator("[data-employee-rate]");
            await rate.waitFor();
            assert.equal(await rate.inputValue(), "15000");
            await rate.fill("25000");
            await rate.press("Escape");
            assert.equal(await rate.inputValue(), "15000");
            assert.equal(writes.length, 0);
            await rate.fill("20000");
            await rate.press("Tab");
            await page.locator("[data-confirm-cancel]").click();
            assert.equal(await rate.inputValue(), "15000");
            assert.equal(writes.length, 0);
            await rate.fill("30000");
            await rate.press("Enter");
            await page.locator("[data-confirm-accept]").click();
            await page.locator('[data-payroll][aria-busy]').waitFor({ state: "detached" });
            assert.equal(await rate.inputValue(), "30000");
            assert.match(await page.locator("[data-entries]").innerText(), /60\.000/);
            assert.equal(employee.hourly_rate, 30000);
            assert.equal(writes.length, 1);
            await rate.fill("0");
            await rate.press("Tab");
            await page.locator("[data-confirm-accept]").click();
            await page.locator('[data-payroll][aria-busy]').waitFor({ state: "detached" });
            assert.equal(await rate.inputValue(), "0");
            assert.equal(writes[1].employee_version, 2, "Repeated edits must use the updated personnel version.");
            fail = true;
            await rate.fill("40000");
            await rate.press("Tab");
            await page.locator("[data-confirm-accept]").click();
            await page.locator('[data-payroll][aria-busy]').waitFor({ state: "detached" });
            assert.equal(await rate.inputValue(), "0", "A failed update must restore the saved rate.");
            await page.getByText("Hồ sơ đã thay đổi. Tải lại trước khi đổi đơn giá.", { exact: true }).waitFor();
            await page.screenshot({ path: path.join(output, `payroll-rate-${width}.png`), fullPage: true });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log("Payroll inline rate desktop/mobile: cancel, confirm, profile version, zero, recalculated salary and conflict rollback: OK");
        console.log("Screenshots: " + output);
    } finally {
        await browser.close();
        fs.rmSync(path.resolve(__dirname, "../storage/sessions/sess_" + sessionId), { force: true });
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
