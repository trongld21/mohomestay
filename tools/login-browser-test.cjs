"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { chromium } = require("playwright");

const base = process.env.LOGIN_TEST_URL || "http://127.0.0.1:8098";
assert.ok(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
const output = process.env.LOGIN_ARTIFACT_DIR || path.join(os.tmpdir(), "mo-login-qa");
fs.mkdirSync(output, { recursive: true });

(async () => {
    const browser = await chromium.launch({ channel: "chrome", headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        const page = await context.newPage();
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        await page.goto(base + "/auth/login");
        assert.equal(await page.locator(".site-header, .site-footer").count(), 0);
        assert.ok(await page.locator('input[name="csrf"]').inputValue());
        await page.getByRole("heading", { name: "Đăng nhập quản lý", exact: true }).waitFor();
        await page.getByLabel("Email", { exact: true }).fill("qa-login-no-account@example.test");
        await page.getByLabel("Mật khẩu", { exact: true }).fill("qa-test-password-only");
        await page.getByRole("button", { name: "Hiện mật khẩu", exact: true }).click();
        assert.equal(await page.locator("#login-password").getAttribute("type"), "text");
        assert.equal(await page.locator("[data-eye-slash]").getAttribute("hidden"), null);
        await page.getByRole("button", { name: "Ẩn mật khẩu", exact: true }).click();
        assert.equal(await page.locator("#login-password").getAttribute("type"), "password");
        await page.getByLabel("Ghi nhớ email", { exact: true }).check();
        await page.getByRole("button", { name: "Đăng nhập", exact: true }).click();
        await page.locator("#login-error").waitFor();
        for (const viewport of [{ width: 1366, height: 650 }, { width: 1280, height: 600 }, { width: 1024, height: 576 }]) {
            await page.setViewportSize(viewport);
            const fit = await page.evaluate(() => ({ height: document.documentElement.scrollHeight, viewport: window.innerHeight, card: document.querySelector('.mo-login-card').getBoundingClientRect().height }));
            assert.ok(fit.height <= fit.viewport, `Login error should fit laptop ${viewport.width}x${viewport.height}: ${JSON.stringify(fit)}.`);
        }
        assert.equal(await page.locator("#login-password").inputValue(), "");
        assert.equal(await page.locator("#login-email").inputValue(), "qa-login-no-account@example.test");
        const storage = await page.evaluate(() => ({ ...localStorage }));
        assert.equal(storage["mo-home-login-email"], "qa-login-no-account@example.test");
        assert.ok(!JSON.stringify(storage).includes("qa-test-password-only"));
        await page.goto(base + "/auth/login");
        assert.equal(await page.locator("#login-email").inputValue(), "qa-login-no-account@example.test");
        await page.getByLabel("Ghi nhớ email", { exact: true }).uncheck();
        assert.equal(await page.evaluate(() => localStorage.getItem("mo-home-login-email")), null);
        await page.locator("#login-email").clear();
        for (const viewport of [{ width: 1536, height: 740 }, { width: 1366, height: 650 }, { width: 1280, height: 600 }, { width: 1024, height: 576 }]) {
            await page.setViewportSize(viewport);
            assert.ok(await page.evaluate(() => document.documentElement.scrollHeight <= window.innerHeight), `Login should fit laptop ${viewport.width}x${viewport.height}.`);
        }
        await page.setViewportSize({ width: 1366, height: 650 });
        await page.locator("h1").click();
        await page.screenshot({ path: path.join(output, "login-laptop.png"), fullPage: true });
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.screenshot({ path: path.join(output, "login-desktop.png"), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: path.join(output, "login-mobile.png"), fullPage: true });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        const submit = await page.locator(".mo-login-submit").boundingBox();
        assert.ok(submit.height >= 44);
        assert.equal(await page.getByText("Cần hỗ trợ đăng nhập?", { exact: true }).count(), 0);
        await page.setViewportSize({ width: 768, height: 1024 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await page.emulateMedia({ reducedMotion: "reduce" });
        await page.locator("#login-email").focus();
        assert.ok(await page.locator("#login-email").evaluate((input) => getComputedStyle(input).outlineStyle !== "none"));
        assert.deepEqual(errors, []);
        const noScript = await browser.newContext({ javaScriptEnabled: false });
        const staticPage = await noScript.newPage();
        await staticPage.goto(base + "/auth/login");
        assert.ok(await staticPage.getByRole("button", { name: "Đăng nhập", exact: true }).isVisible());
        assert.equal(await staticPage.locator("[data-login-password-toggle]").isVisible(), false);
        await noScript.close();
        await context.close();
        console.log("Login desktop/mobile/tablet, password toggle, CSRF, error rendering, email-only storage, keyboard focus and no-JS: OK");
        console.log("Screenshots: " + output);
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
