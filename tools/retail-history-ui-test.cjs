const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const nodes = new Map();
const handlers = {};
const calls = [];
const id = "a".repeat(32);
let completed = false;
const sale = { id, sale_code: "BH-HISTORY", total: 15000, listed_total: 20000, subtotal: 20000, discount_percent: 25, discount_amount: 5000,
    status: "PENDING", created_at: "2026-10-24 01:00:00", checkout_url: "https://pay.payos.vn/web/test", items: [{ product_name: "Water", unit_price: 10000, quantity: 2 }] };
function node() {
    return { value: "", textContent: "", innerHTML: "", disabled: false, hidden: false, open: false, dataset: {}, listeners: {},
        addEventListener(name, fn) { this.listeners[name] = fn; }, reportValidity: () => true, focus() {},
        showModal() { this.open = true; }, close() { this.open = false; } };
}
function one(selector) {
    if (["[data-product-form]", "[data-stock-form]", "[data-cart]", "[data-products]"].includes(selector)) return null;
    if (!nodes.has(selector)) nodes.set(selector, node());
    return nodes.get(selector);
}
one("[data-sales-from]").value = "2026-10-24";
one("[data-sales-to]").value = "2026-10-24";
one("[data-sales-sort]").value = "oldest";
const root = { dataset: { mode: "history" }, querySelector: one, addEventListener(name, fn) { handlers[name] = fn; } };
const context = vm.createContext({
    document: { querySelector: () => root }, window: { LANG_HOME: { abilities: ["sales.view", "sales.cancel", "sales.complete", "sales.create"] } },
    LANG_HOME: { csrf: "test" }, LangUI: { confirm: async () => true }, Date, Intl, Map, URLSearchParams,
    fetch: async (url, config) => {
        calls.push({ url, config });
        let result;
        if (url.includes("sales?")) result = { sales: [{ ...sale, status: completed ? "PAID" : "PENDING" }], revenue: completed ? 15000 : 0, count: 1, page: 1, quantitySold: completed ? 2 : 0, productSales: completed ? [{ product_name: "Water", quantity: 2 }] : [] };
        else if (url.endsWith("/complete")) { completed = true; result = { sale: { ...sale, status: "PAID", completion_method: "MANUAL_TRANSFER", completion_note: "BANK-REF", completed_by: "manager", paid_at: "2026-10-24 02:00:00" } }; }
        else result = { sale };
        return { ok: true, json: async () => result };
    },
});
const click = (selector, dataset) => handlers.click({ target: { closest: (match) => match === selector ? { dataset } : null } });
(async () => {
    vm.runInContext(fs.readFileSync("public/assets/retail.js", "utf8"), context);
    await new Promise((resolve) => setImmediate(resolve));
    const query = new URLSearchParams(calls[0].url.split("?")[1]);
    assert.equal(query.get("from"), "2026-10-24");
    assert.equal(query.get("to"), "2026-10-24");
    assert.equal(query.get("sort"), "oldest");
    assert.ok(calls.every((call) => !call.url.includes("catalog")), "History must not load seller catalog");
    assert.match(one("[data-sales-history]").innerHTML, /data-sale-intent="cancel"/);
    await click("[data-sale-open]", { saleOpen: id });
    assert.match(one("[data-sale-detail]").innerHTML, /data-sale-action="complete"/);
    one("[data-sale-note]").value = "BANK-REF";
    await click("[data-sale-action]", { saleAction: "complete" });
    const completion = calls.find((call) => call.url.endsWith("/complete"));
    assert.equal(JSON.parse(completion.config.body).note, "BANK-REF");
    assert.match(one("[data-sale-detail]").innerHTML, /BANK-REF/);
    assert.ok(!one("[data-sale-detail]").innerHTML.includes('data-sale-action="complete"'));
    assert.match(one("[data-sales-quantity]").textContent, /2/);
    console.log("History date/sort filters, permission actions, manual completion and quantity refresh: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
