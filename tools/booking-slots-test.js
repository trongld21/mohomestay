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

assert.deepEqual(configuredSlots(room).map(slot => slot.id), ['afternoon'], 'only enabled fixed-time packages are bookable slots');

const overnight = slotWindow({ mode: 'FIXED_TIME', checkInTime: '22:00', checkOutTime: '08:00' }, '2027-01-15');
assert.equal(overnight.start.toISOString(), '2027-01-15T15:00:00.000Z', 'slot starts in Vietnam timezone');
assert.equal(overnight.end.toISOString(), '2027-01-16T01:00:00.000Z', 'overnight slot ends the next day');

const afternoon = slotWindow(room.packages[2], '2027-01-15');
assert.equal(slotState(afternoon, [], new Date('2027-01-15T00:00:00Z')), 'available', 'future slot without overlap is available');
assert.equal(slotState(afternoon, [{ start: '2027-01-15T08:00:00Z', end: '2027-01-15T09:00:00Z' }], new Date('2027-01-15T00:00:00Z')), 'booked', 'overlapping booking locks the slot');
assert.equal(slotState(afternoon, [{ start: '2027-01-15T09:00:00Z', end: '2027-01-15T10:00:00Z' }], new Date('2027-01-15T00:00:00Z')), 'available', 'a booking ending exactly at slot start does not lock it');
assert.equal(slotState(afternoon, [], new Date('2027-01-15T07:00:00Z')), 'past', 'started slot cannot be selected');

console.log('Booking slot behavior tests: OK');
