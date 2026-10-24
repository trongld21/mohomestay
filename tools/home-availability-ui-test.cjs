const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

async function render(roomIds, fail = false) {
    const cards = ["pink", "black"].map((id) => ({
        dataset: { roomCardId: id },
        hidden: false,
        badge: { dataset: {}, textContent: "" },
        querySelector() { return this.badge; },
    }));
    const grid = {
        hidden: false,
        querySelectorAll: () => cards,
        setAttribute() {},
    };
    const status = {};
    const root = { querySelector: (selector) => selector === "[data-public-room-grid]" ? grid : status };
    const context = vm.createContext({
        document: { hidden: false, querySelector: () => root, addEventListener() {} },
        window: { addEventListener() {} },
        fetch: async () => ({ ok: !fail, json: async () => ({ roomIds }) }),
        AbortSignal, setTimeout: () => 0, clearTimeout() {},
    });
    vm.runInContext(fs.readFileSync("public/assets/home-availability.js", "utf8"), context);
    await new Promise(setImmediate);
    assert.equal(grid.hidden, false);
    assert.ok(cards.every((card) => !card.hidden));
    return { cards, status };
}

(async () => {
    const mixed = await render(["pink"]);
    assert.deepEqual(mixed.cards.map((card) => card.badge.textContent), ["Còn phòng", "Hết phòng"]);
    const none = await render([]);
    assert.ok(none.cards.every((card) => card.badge.textContent === "Hết phòng"));
    const all = await render(["pink", "black"]);
    assert.ok(all.cards.every((card) => card.badge.textContent === "Còn phòng"));
    const failed = await render([], true);
    assert.ok(failed.cards.every((card) => card.badge.dataset.state === "unknown"));
    assert.equal(failed.status.hidden, false);
    console.log("Home room cards remain visible with available, sold-out and unknown states: OK");
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
