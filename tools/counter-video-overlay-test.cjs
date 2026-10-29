const assert = require('node:assert/strict');
const {contentRect, nearestFrame} = require('../public/assets/counter-video-overlay.js');

const wide = contentRect(1000, 500, 640, 352);
assert.equal(wide.height, 500);
assert.ok(Math.abs(wide.x - 45.454545) < 0.001);
const tall = contentRect(360, 300, 640, 352);
assert.equal(tall.width, 360);
assert.equal(tall.y, 51);
const frames = [{atMs: 1000, boxes: [{id: 1}]}, {atMs: 1500, boxes: []}];
assert.equal(nearestFrame(frames, 1100), frames[0]);
assert.equal(nearestFrame(frames, 1490), frames[1]);
assert.equal(nearestFrame(frames, 3000), null);
assert.equal(nearestFrame(frames, NaN), null);
assert.equal(nearestFrame([], 1000), null);
console.log('Video overlay: letterboxing, timeline matching and stale detection expiry OK');
