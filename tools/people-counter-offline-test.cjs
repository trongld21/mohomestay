const assert = require('node:assert/strict');
const {parseReport} = require('../public/assets/people-counter-offline.js');

const valid = {
    status: 'complete', sourceFile: 'ca-toi.mp4', sourceSha256: 'a'.repeat(64),
    processedFrames: 90, videoSeconds: 7.5, cameraStart: null,
    totals: {IN: 3, OUT: 2}, expected: {IN: 0, OUT: null},
    calibrationVerified: false, operationalEventsSent: 0
};
assert.equal(parseReport(JSON.stringify(valid)).expected.IN, 0);
assert.equal(parseReport(JSON.stringify({...valid, status: 'incomplete'})).status, 'incomplete');
for (const bad of [null, [], {}, {...valid, totals: {IN: -1, OUT: 2}},
    {...valid, totals: {IN: '3', OUT: 2}}, {...valid, expected: {IN: false, OUT: null}},
    {...valid, operationalEventsSent: 1}, {...valid, cameraStart: 'not-a-date'},
    {...valid, sourceSha256: '../private'}, {...valid, videoSeconds: Infinity}]) {
    assert.throws(() => parseReport(JSON.stringify(bad)));
}
assert.throws(() => parseReport('{broken'));
console.log('Offline report validation: OK');
