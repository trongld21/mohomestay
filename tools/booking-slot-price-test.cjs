const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const source = fs.readFileSync("public/assets/rooms.js", "utf8");
const start = source.indexOf("        function renderSlotPrice(");
assert.ok(start >= 0, "Booking cards must render the authoritative selected-time price");
const end = source.indexOf("        function renderSlots()", start);
const context = {
    priceQuote: null,
    priceError: "",
    cash: (value) => `${value}đ`,
    esc: (value) => String(value).replaceAll("<", "&lt;"),
};
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);
const slot = { price: 169000, basePrice: 169000 };
context.priceQuote = { price: 135000, basePrice: 169000, percent: 20 };
let output = context.renderSlotPrice(slot, true);
assert.match(output, /135000đ/);
assert.match(output, /<del[^>]*>169000đ<\/del>/);
assert.match(output, /Giảm 20%/);
assert.doesNotMatch(context.renderSlotPrice(slot, false), /135000đ/, "Another package must not inherit the selected package discount");
context.priceQuote = { price: 169000, basePrice: 169000, percent: 0 };
output = context.renderSlotPrice(slot, true);
assert.match(output, /169000đ/);
assert.doesNotMatch(output, /<del|Giảm/, "Leaving the promotion window clears its old price and badge");
context.priceQuote = null;
assert.doesNotMatch(context.renderSlotPrice(slot, true), /135000đ|169000đ/, "Pending quotes must not present stale prices");
context.priceError = "Offline";
assert.match(context.renderSlotPrice(slot, true), /Chưa kiểm tra được giá/);
console.log("Booking card selected-time discounts, boundaries and pending prices: OK");
