const fs = require("node:fs");
const vm = require("node:vm");
const assert = require("node:assert/strict");
const nodes = new Map();
function node() {
    return { textContent: "", innerHTML: "", disabled: false, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        setAttribute() {}, removeAttribute() {},
    };
}
function find(selector) {
    if (!nodes.has(selector)) nodes.set(selector, node());
    return nodes.get(selector);
}
const filter = find("[data-expense-filter]");
filter.values = { from: "2026-10-24", to: "2026-10-24", q: "" };
const form = find("[data-expense-form]");
form.elements = { spentAt: { value: "" }, includeCashflow: { checked: true } };
form.values = { spentAt: "2026-10-24T14:00", category: "Supplies", amount: "50000", recipient: "Vendor", note: "Test" };
form.reset = () => {};
form.reportValidity = () => true;
form.querySelectorAll = () => [];
const dialog = find("[data-expense-dialog]");
dialog.showModal = () => { dialog.open = true; };
dialog.close = () => { dialog.open = false; };
const requests = [];
const root = { querySelector: find, querySelectorAll: () => [] };
const context = {
    document: { querySelector: () => root },
    window: { LANG_HOME: { csrf: "csrf-token" } },
    Intl, Date, URLSearchParams, Uint8Array,
    crypto: { getRandomValues: (array) => array.fill(1) },
    FormData: class {
        constructor(target) { this.target = target; }
        *[Symbol.iterator]() { yield* Object.entries(this.target.values); }
    },
    fetch: async (url, options) => {
        requests.push({ url, options });
        return { ok: true, json: async () => options?.method === "POST" ? { voucher: { voucher_code: "PC-TEST" } } : {
            count: 1, total: 50000, cashflow: 50000,
            vouchers: [{ voucher_code: "PC-TEST", spent_at: "2026-10-24 07:00:00", category: "<script>alert(1)</script>", amount: 50000, recipient: "Vendor", note: "Test", include_cashflow: 1 }],
        } };
    },
};
const tick = () => new Promise((resolve) => setImmediate(resolve));
(async () => {
    vm.runInNewContext(fs.readFileSync("public/assets/expenses.js", "utf8"), context);
    await tick();
    assert.match(requests[0].url, /from=2026-10-24&to=2026-10-24/);
    assert.match(find("[data-expense-list]").innerHTML, /&lt;script&gt;/);
    assert.equal(find("[data-expense-list]").innerHTML.includes("<script>"), false);
    find("[data-expense-new]").listeners.click();
    assert.equal(dialog.open, true);
    await form.listeners.submit({ preventDefault() {} });
    const post = requests.find((request) => request.options?.method === "POST");
    const body = JSON.parse(post.options.body);
    assert.equal(body.amount, 50000);
    assert.equal(body.includeCashflow, true);
    assert.match(body.requestId, /^[a-f0-9]{32}$/);
    assert.equal(post.options.headers["X-CSRF-Token"], "csrf-token");
    assert.equal(dialog.open, false);
    assert.match(find("[data-expense-status]").textContent, /PC-TEST/);
    console.log("Expense UI filters, escaped notes, typed payload, CSRF and save refresh: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
