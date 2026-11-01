const assert = require('node:assert/strict');
require('../public/assets/booking-slots.js');

const slot = { mode: 'FIXED_TIME', checkInTime: '21:00', checkOutTime: '09:00' };
const now = new Date('2027-01-01T00:00:00+07:00');
const slots = globalThis.MoBookingSlots;
const late = slots.manualSlotWindow(slot, '2027-01-15', '21:30', now);
assert.equal(late.start.toISOString(), '2027-01-15T14:30:00.000Z');
assert.equal(late.end.toISOString(), '2027-01-16T02:00:00.000Z');
const intervals = [
    { start: '2027-01-15T11:00:00Z', end: '2027-01-15T14:00:00Z', status: 'CONFIRMED' },
    { start: '2027-01-15T14:00:00Z', end: '2027-01-15T14:30:00Z', status: 'CLEANING' },
];
assert.equal(slots.slotState(slots.manualSlotWindow(slot, '2027-01-15', '21:00', now), intervals, now), 'booked');
assert.equal(slots.slotState(late, intervals, now), 'available');
assert.equal(slots.slotState(late, [...intervals, { start: '2027-01-15T15:00:00Z', end: '2027-01-15T16:00:00Z', status: 'CONFIRMED' }], now), 'booked');
assert.equal(slots.manualSlotWindow(slot, '2027-01-15', '00:30', now).start.toISOString(), '2027-01-15T17:30:00.000Z');
for (const time of ['20:30', '09:00', '21:15', 'invalid']) {
    assert.equal(slots.manualSlotWindow(slot, '2027-01-15', time, now), null);
}
assert.equal(slots.slotWindow(slot, '2027-01-15', '21:30', now).start.toISOString(), '2027-01-15T14:00:00.000Z');
console.log('Manual overnight arrival, cleaning boundary, real conflicts and public booking: OK');
