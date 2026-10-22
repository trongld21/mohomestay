const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const node = (attrs = {}) => ({
    ...attrs,
    textContent: "",
    handlers: {},
    classList: { toggle() {}, add() {}, remove() {} },
    setAttribute(name, value) {
        this[name] = value;
    },
    addEventListener(name, callback) {
        this.handlers[name] = callback;
    },
    scrollIntoView() {},
    focus() {
        this.focused = true;
    },
});
const main = node({ alt: "First", getAttribute: () => "/first.jpg" });
const thumbnails = [1, 2, 3].map((number) =>
    node({ dataset: { galleryImage: `/photo-${number}.jpg`, galleryCaption: `Photo ${number}` } }),
);
const dialog = node();
dialog.showModal = () => {
    dialog.open = true;
};
dialog.close = () => {
    dialog.open = false;
    dialog.handlers.close();
};
const elements = {
    "[data-gallery-main]": main,
    "[data-gallery-caption]": node(),
    "[data-gallery-current]": node(),
    "[data-room-lightbox]": dialog,
    "[data-gallery-expand]": node(),
    "[data-lightbox-image]": node(),
    "[data-lightbox-caption]": node(),
    "[data-lightbox-position]": node(),
};
const gallery = node({
    querySelector: (selector) => elements[selector],
    querySelectorAll: () => thumbnails,
});
const document = { querySelector: () => gallery, body: node() };
vm.runInNewContext(fs.readFileSync(require.resolve("../public/assets/room-detail.js"), "utf8"), {
    document,
});
const click = (selector, value = {}) =>
    gallery.handlers.click({ target: { closest: (input) => (input === selector ? value : null) } });
click("[data-gallery-prev]");
assert.equal(main.src, "/photo-3.jpg");
assert.equal(elements["[data-lightbox-position]"].textContent, "3 / 3");
click("[data-gallery-next]");
assert.equal(main.src, "/photo-1.jpg");
click("[data-gallery-image]", thumbnails[1]);
assert.equal(main.alt, "Photo 2");
assert.equal(thumbnails[1]["aria-pressed"], "true");
assert.equal(thumbnails[0]["aria-pressed"], "false");
click("[data-gallery-expand]");
assert.equal(dialog.open, true);
gallery.handlers.keydown({ key: "ArrowRight", preventDefault() {} });
assert.equal(elements["[data-lightbox-image]"].src, "/photo-3.jpg");
click("[data-lightbox-close]");
assert.equal(dialog.open, false);
assert.equal(elements["[data-gallery-expand]"].focused, true);
console.log("Room gallery interaction tests: OK");
