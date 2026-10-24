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
assert.equal(late.start.toISOString(), '2027-01-15T14:00:00.000Z');
assert.equal(late.end.toISOString(), '2027-01-16T02:00:00.000Z');
assert.equal(slotState(late, [{start:'2027-01-15T14:00:00Z', end:'2027-01-15T14:29:00Z'}], lateNow), 'booked', 'Original overnight window must not overlap another booking');
assert.equal(slotState(late, [{start:'2027-01-15T15:00:00Z', end:'2027-01-15T16:00:00Z'}], lateNow), 'booked', 'Future overlap still blocks late arrival');
assert.equal(slotState(duration, [], new Date('2027-01-15T08:00:00Z')), 'past', 'Duration slots still require future start');

const nightPackage = { mode: 'FIXED_TIME', checkInTime: '21:00', checkOutTime: '09:00' };
const earlyNow = new Date('2026-10-24T00:50:25+07:00');
const remainingNight = slotWindow(nightPackage, '2026-10-23', '', earlyNow);
assert.equal(remainingNight.start.toISOString(), '2026-10-23T14:00:00.000Z', 'Previous night retains scheduled evening');
assert.equal(remainingNight.end.toISOString(), '2026-10-24T02:00:00.000Z', 'Today after midnight checks out this morning');
assert.equal(slotState(remainingNight, [{ start: '2026-10-23T15:00:00Z', end: '2026-10-24T01:00:00Z' }], earlyNow), 'booked', 'An existing overnight stay blocks the remaining night');
assert.equal(slotState(remainingNight, [{ start: '2026-10-24T02:30:00Z', end: '2026-10-24T05:00:00Z', status: 'CONFIRMED' }], earlyNow), 'booked', 'Remaining overnight stay preserves the one-hour cleaning gap before the next guest');
assert.equal(slotState(remainingNight, [{ start: '2026-10-24T03:00:00Z', end: '2026-10-24T05:00:00Z', status: 'CONFIRMED' }], earlyNow), 'available', 'A next guest exactly one hour after morning checkout keeps the full cleaning gap');
const tomorrowNight = slotWindow(nightPackage, '2026-10-25', '', earlyNow);
assert.equal(tomorrowNight.start.toISOString(), '2026-10-25T14:00:00.000Z', 'Future dates keep their scheduled evening');
const afterCheckout = slotWindow(nightPackage, '2026-10-24', '', new Date('2026-10-24T09:00:00+07:00'));
assert.equal(afterCheckout.start.toISOString(), '2026-10-24T14:00:00.000Z', 'At checkout today refers to tonight again');
const lastMinuteNow = new Date('2026-10-24T08:59:30+07:00');
assert.equal(slotState(slotWindow(nightPackage, '2026-10-23', '', lastMinuteNow), [], lastMinuteNow), 'available', 'Previous night preserves its full window until checkout');

console.log('Booking slot behavior tests: OK');

const nightRoom = { packages: [{ ...nightPackage, enabled: true }] };
assert.equal(globalThis.MoBookingSlots.earliestBookingDate(nightRoom, earlyNow), '2026-10-23');
assert.equal(globalThis.MoBookingSlots.earliestBookingDate(nightRoom, new Date('2026-10-24T09:00:00+07:00')), '2026-10-23');
assert.equal(globalThis.MoBookingSlots.earliestBookingDate({ packages: [] }, earlyNow), '2026-10-24');
const previousNight = slotWindow(nightPackage, '2026-10-23', '', earlyNow);
assert.equal(previousNight.end.toISOString(), remainingNight.end.toISOString());
assert.equal(previousNight.start.toISOString(), remainingNight.start.toISOString());
assert.equal(slotState(slotWindow(nightPackage, '2026-10-22', '', earlyNow), [], earlyNow), 'past');
console.log('Previous-day booking is limited to an ongoing overnight package: OK');

for (const checkInTime of ['22:00', '10:00']) {
    const testNow = new Date('2027-01-15T00:00:00+07:00');
    const night = slotWindow({ mode: 'FIXED_TIME', checkInTime, checkOutTime: '10:00' }, '2027-01-15', '', testNow);
    assert.equal(night.end.toISOString(), '2027-01-16T03:00:00.000Z');
    const occupied = [
        { start: night.start.toISOString(), end: night.end.toISOString(), status: 'CONFIRMED' },
        { start: night.end.toISOString(), end: new Date(night.end.getTime() + 3600000).toISOString(), status: 'CLEANING' },
    ];
    for (const durationMinutes of [180, 360]) {
        const hourly = slotWindow({ mode: 'DURATION', durationMinutes }, '2027-01-16', '01:00', testNow);
        assert.equal(slotState(hourly, occupied, testNow), 'booked');
        assert.equal(slotState(night, [{ start: hourly.start.toISOString(), end: hourly.end.toISOString(), status: 'CONFIRMED' }], testNow), 'booked');
        const cleaning = slotWindow({ mode: 'DURATION', durationMinutes }, '2027-01-16', '10:30', testNow);
        assert.equal(slotState(cleaning, occupied, testNow), 'booked');
        const afterCleaning = slotWindow({ mode: 'DURATION', durationMinutes }, '2027-01-16', '11:00', testNow);
        assert.equal(slotState(afterCleaning, occupied, testNow), 'available');
    }
}
console.log('Overnight and 24-hour packages block 3h/6h conflicts in both directions: OK');

const fullDayNow = new Date('2027-01-15T00:00:00+07:00');
const fullDayIntervals = [
    { start: '2027-01-14T17:00:00Z', end: '2027-01-15T17:00:00Z', status: 'CONFIRMED' },
    { start: '2027-01-15T17:00:00Z', end: '2027-01-15T18:00:00Z', status: 'CLEANING' },
];
for (const durationMinutes of [180, 360, 1440]) {
    const slot = { mode: 'DURATION', durationMinutes };
    assert.equal(globalThis.MoBookingSlots.durationDayState(slot, '2027-01-15', fullDayIntervals, fullDayNow), 'booked');
    assert.equal(globalThis.MoBookingSlots.durationDayState(slot, '2027-01-15', [], fullDayNow), 'available');
    assert.equal(globalThis.MoBookingSlots.durationDayState(slot, '2027-01-14', [], fullDayNow), 'past');
}
console.log('Full-day occupancy disables duration packages before a time is selected: OK');

for (const durationMinutes of [180, 360, 1440]) {
    const slot = { mode: 'DURATION', durationMinutes };
    assert.equal(slotState(slotWindow(slot, '2027-01-15', '20:59', fullDayNow), [], fullDayNow), 'available');
    assert.equal(slotState(slotWindow(slot, '2027-01-15', '21:00', fullDayNow), [], fullDayNow), 'available');
    assert.equal(slotState(slotWindow(slot, '2027-01-15', '23:00', fullDayNow), [], fullDayNow), 'available');
    assert.equal(globalThis.MoBookingSlots.durationDayState(slot, '2027-01-15', [], new Date('2027-01-15T21:00:00+07:00')), 'available');
    const eveningNow = new Date('2027-01-15T22:00:00+07:00');
    assert.equal(slotState(slotWindow(slot, '2027-01-16', '14:00', eveningNow), [], eveningNow), 'available');
}
assert.equal(slotState(slotWindow({ mode: 'FIXED_TIME', checkInTime: '22:00', checkOutTime: '10:00' }, '2027-01-15', '', fullDayNow), [], fullDayNow), 'available');
console.log('Late arrivals and overnight packages remain available: OK');

const regular = { mode: 'DURATION', durationMinutes: 180 };
const atNine = new Date('2027-01-15T21:00:00+07:00');
assert.equal(slotState(slotWindow(regular, '2027-01-15', '23:00', atNine), [], atNine), 'available');
assert.equal(slotState(slotWindow(regular, '2027-01-15', '20:00', atNine), [], atNine), 'past');
console.log('Regular packages remain available after 21:00; past arrivals remain invalid: OK');

const extensionBase = slotWindow({ mode: 'DURATION', durationMinutes: 180 }, '2027-01-15', '22:00', fullDayNow);
const extension = globalThis.MoBookingSlots.extendedWindow(extensionBase, 2);
assert.equal(extension.end.getTime() - extension.start.getTime(), 5 * 3600000);
assert.equal(extensionBase.end.getTime() - extensionBase.start.getTime(), 3 * 3600000, 'Keep the base package unchanged');
const followingStay = [{ start: '2027-01-16T04:00:00+07:00', end: '2027-01-16T07:00:00+07:00', status: 'CONFIRMED' }];
assert.equal(slotState(globalThis.MoBookingSlots.extendedWindow(extensionBase, 1), followingStay, fullDayNow), 'available');
assert.equal(slotState(globalThis.MoBookingSlots.extendedWindow(extensionBase, 2), followingStay, fullDayNow), 'available');
const tighterStay = [{ ...followingStay[0], start: '2027-01-16T03:30:00+07:00' }];
assert.equal(slotState(globalThis.MoBookingSlots.extendedWindow(extensionBase, 2), tighterStay, fullDayNow), 'booked', 'Extra hours must leave the full cleaning hour');
console.log('Extra hours extend the stay and preserve cleaning conflict checks: OK');
