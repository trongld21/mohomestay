const assert = require("node:assert/strict");
const { pages, windowFor, create } = require("../public/assets/pagination.js");
const items = Array.from({ length: 26 }, (_, index) => index);
assert.deepEqual(windowFor(items, 2).items, items.slice(10, 20));
assert.equal(windowFor(items, 99).page, 3);
assert.equal(windowFor(items, 3).end, 26);
assert.equal(windowFor(items.slice(0, 2), 3).page, 1);
assert.equal(windowFor([], 1).start, 0);
assert.equal(windowFor(items, -1).page, 1);
assert.deepEqual(pages(1, 15), [1, 2, 3, 4, "…", 15]);
assert.deepEqual(pages(8, 15), [1, "…", 7, 8, 9, "…", 15]);
assert.deepEqual(pages(15, 15), [1, "…", 12, 13, 14, 15]);
assert.deepEqual(pages(1, 1), [1]);
console.log("Pagination tests: OK");

let click;
let changeCount = 0;
let focusCount = 0;
global.document = {
    addEventListener: (event, callback) => {
        click = callback;
    },
    querySelector: () => ({ focus: () => focusCount++ }),
};
const pager = create("test-list", () => changeCount++);
const press = (page, name = "test-list", disabled = false) =>
    click({
        target: { closest: () => ({ dataset: { page, pagination: name }, disabled }) },
    });
press(2);
assert.equal(pager.slice(items).page, 2);
assert.equal(changeCount, 1);
assert.equal(focusCount, 1);
press(3, "other-list");
press(3, "test-list", true);
assert.equal(changeCount, 1);
assert.equal(pager.slice(items).page, 2);
press(3);
assert.equal(pager.slice(items.slice(0, 20)).page, 2);
pager.reset();
const first = pager.slice(items);
assert.equal(first.page, 1);
assert.match(pager.render(first, "mã"), /aria-current="page"/);
assert.match(pager.render(first, "mã"), /Hiển thị 1–10 \/ 26 mã/);
delete global.document;
console.log("Pagination interaction tests: OK");
