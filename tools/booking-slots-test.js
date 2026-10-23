'use strict';

const assert = require('node:assert/strict');

require('../public/assets/booking-slots.js');

const { configuredSlots, slotWindow, slotState } = globalThis.MoBookingSlots;

const room = {
  packages: [
    { id: 'duration', mode: 'DURATION', enabled: true, durationMinutes: 180 },
    { id: 'disabled', mode: 'FIXED_TIME', enabled: false, checkInTime: '08:00', checkOutTime: '11:00' },
    { id: 'afternoon', mode: 'FIXED_TIME', enabled: true, checkInTime: '13:00', checkOutTime: '16:00' },
  ],
};

assert.deepEqual(configuredSlots(room).map(slot => slot.id), ['duration', 'afternoon'], 'all enabled packages are bookable slots');

const duration = slotWindow(room.packages[0], '2027-01-15', '14:30');
assert.equal(duration.start.toISOString(), '2027-01-15T07:30:00.000Z', 'duration package uses the selected Vietnam start time');
assert.equal(duration.end.toISOString(), '2027-01-15T10:30:00.000Z', 'duration package ends after its configured minutes');
assert.equal(slotWindow(room.packages[0], '2027-01-15', ''), null, 'duration package waits for a start time');

const overnight = slotWindow({ mode: 'FIXED_TIME', checkInTime: '22:00', checkOutTime: '08:00' }, '2027-01-15');
assert.equal(overnight.start.toISOString(), '2027-01-15T15:00:00.000Z', 'slot starts in Vietnam timezone');
assert.equal(overnight.end.toISOString(), '2027-01-16T01:00:00.000Z', 'overnight slot ends the next day');

const afternoon = slotWindow(room.packages[2], '2027-01-15');
assert.equal(slotState(afternoon, [], new Date('2027-01-15T00:00:00Z')), 'available', 'future slot without overlap is available');
assert.equal(slotState(afternoon, [{ start: '2027-01-15T08:00:00Z', end: '2027-01-15T09:00:00Z' }], new Date('2027-01-15T00:00:00Z')), 'booked', 'overlapping booking locks the slot');
assert.equal(slotState(afternoon, [{ start: '2027-01-15T09:00:00Z', end: '2027-01-15T10:00:00Z' }], new Date('2027-01-15T00:00:00Z')), 'available', 'a booking ending exactly at slot start does not lock it');
assert.equal(slotState(afternoon, [], new Date('2027-01-15T07:00:00Z')), 'available', 'ongoing fixed slot remains available');
assert.equal(slotState(afternoon, [], new Date('2027-01-15T09:00:00Z')), 'past', 'ended fixed slot is unavailable');
const lateNow = new Date('2027-01-15T14:30:00Z');
const late = slotWindow({mode: 'FIXED_TIME', checkInTime: '21:00', checkOutTime: '09:00'}, '2027-01-15', '', lateNow);
assert.equal(late.start.toISOString(), '2027-01-15T14:30:00.000Z');
assert.equal(late.end.toISOString(), '2027-01-16T02:00:00.000Z');
assert.equal(slotState(late, [{start:'2027-01-15T14:00:00Z', end:'2027-01-15T14:29:00Z'}], lateNow), 'available', 'Past occupied portion does not block late arrival');
assert.equal(slotState(late, [{start:'2027-01-15T15:00:00Z', end:'2027-01-15T16:00:00Z'}], lateNow), 'booked', 'Future overlap still blocks late arrival');
assert.equal(slotState(duration, [], new Date('2027-01-15T08:00:00Z')), 'past', 'Duration slots still require future start');

console.log('Booking slot behavior tests: OK');
