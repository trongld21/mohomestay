const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const nodes = new Map();
const listeners = {};
const id = "a".repeat(32);
const product = { id, name: '<img src=x onerror="bad()">', price: 15000, is_active: 1 };
let failed = true;
let salePosts = [];
let uuidCount = 0;
function node() {
    return {
        value: "", textContent: "", innerHTML: "", disabled: false, hidden: false, open: false,
        addEventListener() {}, reportValidity: () => true, focus() {},
        showModal() { this.open = true; }, close() { this.open = false; },
    };
}
function one(selector) {
    if (selector === "[data-product-form]") return null;
    if (!nodes.has(selector)) nodes.set(selector, node());
    return nodes.get(selector);
}
const sale = {
    id: "b".repeat(32), sale_code: "BH-TEST", total: 25000, listed_total: 30000,
    status: "PENDING", created_at: "2026-10-24 07:00:00", checkout_url: "https://pay.payos.vn/web/test",
    items: [{ product_name: product.name, unit_price: 15000, quantity: 2 }],
};
const root = { dataset: { mode: "sales" }, querySelector: one, addEventListener(event, fn) { listeners[event] = fn; } };
const context = vm.createContext({
    document: { querySelector: () => root },
    window: { LANG_HOME: { abilities: ["sales.view", "sales.create", "sales.price"] } },
    LANG_HOME: { csrf: "csrf" },
    crypto: { randomUUID() { uuidCount++; return "12345678-1234-1234-1234-123456789012"; } },
    Intl, Date, Map,
    fetch: async (url, config) => {
        let result;
        if (url.endsWith("sales/catalog")) result = { products: [product] };
        else if (config.method === "POST") {
            salePosts.push(JSON.parse(config.body));
            if (failed) { failed = false; throw new Error("Network error"); }
            result = { sale };
        } else result = { sales: [], revenue: 0, count: 0, page: 1 };
        return { ok: true, json: async () => result };
    },
});
const click = (selector, data = {}) => listeners.click({ target: { closest: (match) => match === selector ? { dataset: data } : null } });
(async () => {
    vm.runInContext(fs.readFileSync("public/assets/retail.js", "utf8"), context);
    await new Promise((resolve) => setImmediate(resolve));
    assert.match(one("[data-products]").innerHTML, /&lt;img/);
    assert.ok(!one("[data-products]").innerHTML.includes('<img src=x'));
    await click("[data-add-product]", { addProduct: id });
    await click("[data-add-product]", { addProduct: id });
    assert.equal(one("[data-cart-total]").value, 30000);
    one("[data-cart-total]").value = "25000";
    await click("[data-sale-create]");
    assert.equal(one("[data-cart-total]").value, "25000", "Failed request must preserve negotiated total for retry");
    await click("[data-sale-create]");
    assert.equal(salePosts.length, 2);
    assert.equal(uuidCount, 1, "Retry must preserve request id");
    assert.equal(salePosts[0].requestId, salePosts[1].requestId);
    assert.equal(salePosts[1].total, 25000);
    assert.equal(salePosts[1].items[0].quantity, 2);
    assert.match(one("[data-sale-detail]").innerHTML, /pay.payos.vn/);
    assert.match(one("[data-sale-detail]").innerHTML, /&lt;img/);
    assert.equal(one("[data-sale-dialog]").open, true);
    assert.equal(one("[data-cart-total]").value, "");
    console.log("Retail cart, negotiated totals, network retry identity, QR handoff and escaping: OK");
})().catch((error) => { console.error(error); process.exitCode = 1; });
