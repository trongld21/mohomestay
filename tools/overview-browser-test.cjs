"use strict";

// Production dashboard template with fixture report data; no database or network writes.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { spawnSync } = require("node:child_process");
const { chromium } = require("playwright");

const output = process.env.OVERVIEW_ARTIFACT_DIR || path.join(os.tmpdir(), "mo-overview-qa");
fs.mkdirSync(output, { recursive: true });
const report = {
    from: "2026-10-25", to: "2026-10-31", updatedAt: "2026-11-02T08:00:00Z", days: [],
    totals: { collected: 0, room: 0, retail: 0, surcharge: 0, expense: 0, cash: 0, transfer: 0, other: 0, bookings: 0 },
};
for (let day = 25; day <= 31; day++) {
    const room = 350000 + (day % 3) * 250000;
    const retail = 40000 + (day % 4) * 20000;
    const totals = { room, retail, surcharge: 50000, expense: 80000, cash: retail, transfer: room + 50000, other: 0, bookings: 1, collected: room + retail + 50000 };
    Object.keys(totals).forEach((key) => { report.totals[key] += totals[key]; });
    const date = `2026-10-${day}`;
    report.days.unshift({ date, totals, transactions: [
        { type: "room", code: `MO-${day}`, room: day % 2 ? "Phòng Nắng" : "Phòng Mây", amount: room, time: `${date}T03:00:00Z`, payment: "transfer" },
        { type: "retail", code: `BH-${day}`, room: "Phòng Nắng", amount: retail, time: `${date}T08:00:00Z`, payment: "cash" },
        { type: "surcharge", code: `MO-${day}`, room: "Phòng Nắng", amount: 50000, time: `${date}T10:00:00Z`, payment: "transfer" },
        { type: "expense", code: `PC-${day}`, room: "Vật tư", amount: 80000, time: `${date}T06:00:00Z`, payment: null },
    ] });
}

function render(data) {
    const code = `
        require "src/overview-view.php";
        function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); }
        render_overview_dashboard(json_decode($argv[1], true), "", "2026-10-25", "2026-10-31", new DateTimeImmutable("2026-11-02", new DateTimeZone("Asia/Ho_Chi_Minh")));
    `;
    const result = spawnSync("php", ["-r", code, JSON.stringify(data)], { cwd: path.resolve(__dirname, ".."), encoding: "utf8" });
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.replace(/<script defer[^>]*><\/script>/g, "");
}

(async () => {
    const browser = await chromium.launch({ channel: "chrome", headless: true });
    try {
        const css = ["app.css", "tailwind.css"].map((file) => fs.readFileSync(path.resolve(__dirname, "../public/assets", file), "utf8")).join("\n");
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 1000 } });
            const errors = [];
            page.on("pageerror", (error) => errors.push(error.message));
            page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
            await page.setContent(`<html><head><meta charset="utf-8"><style>${css}</style></head><body class="admin-page"><main class="p-4 sm:p-8">${render(report)}</main></body></html>`);
            await page.addScriptTag({ path: path.resolve(__dirname, "../public/assets/overview.js") });
            assert.equal(await page.locator('[data-chart-series="day"]:visible').count(), 2);
            const chart = page.locator("[data-overview-chart]").first();
            await chart.getByRole("button", { name: "Theo giờ", exact: true }).click();
            assert.ok(await chart.locator('[data-chart-series="hour"]').isVisible());
            assert.equal(await chart.locator('[data-chart-series="day"]').isVisible(), false);
            await chart.getByRole("button", { name: "Theo ngày", exact: true }).click();
            const form = page.locator("[data-overview-filter]");
            await form.evaluate((node) => node.addEventListener("submit", (event) => event.preventDefault()));
            await page.locator("[data-overview-preset]").selectOption({ label: "7 ngày qua" });
            assert.equal(await form.locator('[name="from"]').inputValue(), "2026-10-27");
            assert.equal(await form.locator('[name="to"]').inputValue(), "2026-11-02");
            await form.locator('[name="from"]').fill("2026-01-01");
            assert.equal(await form.locator('[name="to"]').evaluate((input) => input.validity.valid), false);
            await form.locator('[name="from"]').fill("2026-10-25");
            await form.locator('[name="to"]').fill("2026-10-31");
            assert.equal(await form.locator('[name="to"]').evaluate((input) => input.validity.valid), true);
            const download = page.waitForEvent("download");
            await page.locator("[data-overview-export]").click();
            const file = await download;
            assert.equal(file.suggestedFilename(), "mo-tong-quan-2026-10-25-2026-10-31.csv");
            const exportPath = path.join(output, `overview-export-${width}.csv`);
            await file.saveAs(exportPath);
            const csv = fs.readFileSync(exportPath, "utf8");
            assert.ok(csv.startsWith("\uFEFF") && csv.includes("Chuyển khoản"));
            assert.equal(csv.split("\r\n").length, 29);
            assert.equal(await page.locator('[data-overview-refresh]').isVisible(), true);
            await page.screenshot({ path: path.join(output, `overview-${width}.png`), fullPage: true });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
            assert.deepEqual(errors, []);
            await page.close();
        }
        const empty = structuredClone(report);
        empty.totals = Object.fromEntries(Object.keys(empty.totals).map((key) => [key, 0]));
        empty.days = empty.days.map((day) => ({ ...day, totals: empty.totals, transactions: [] }));
        assert.ok(render(empty).includes("Chưa có tiền nhận trong khoảng ngày đã chọn."));
        console.log("Overview desktop/mobile: date presets, 93-day validation, day/hour charts, CSV export, empty state and console: OK");
        console.log("Screenshots: " + output);
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
