const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Native popup controls must retain the browser's focus and selection behavior.
const nativeSelect = {
    hasAttribute: name => name === 'data-native-select',
    dataset: {},
};
let mutationCallback;
const context = {
    document: {
        querySelectorAll: () => [nativeSelect],
        body: {},
    },
    window: {},
    MutationObserver: class {
        constructor(callback) { mutationCallback = callback; }
        observe() {}
    },
};
vm.runInNewContext(fs.readFileSync(require.resolve('../public/assets/select.js'), 'utf8'), context);
assert.deepEqual(nativeSelect.dataset, {});
context.window.MoSelect.init({
    matches: () => true,
    hasAttribute: nativeSelect.hasAttribute,
    dataset: nativeSelect.dataset,
    querySelectorAll: () => [],
});
assert.deepEqual(nativeSelect.dataset, {});
assert.equal(typeof mutationCallback, 'function');
console.log('Native select opt-out tests: OK');
