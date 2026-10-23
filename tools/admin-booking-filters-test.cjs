const assert = require('node:assert/strict');
const {matches, isFinished, tone} = require('../public/assets/admin-booking-filters.js');
const filters = {query: '', status: 'all', room: 'all', from: '2026-10-23', to: '2026-10-23', showFinished: false};
const stay = {status: 'CONFIRMED', payment_status: 'PAID', room_name: 'Mơ', guest_name: 'Khách', check_in: '2026-10-22 16:00:00', check_out: '2026-10-23 02:00:00'};
assert.equal(matches(stay, filters), true, 'Overnight stays overlap Vietnam date');
assert.equal(matches({...stay, check_out: '2026-10-22 17:00:00'}, filters), false, 'Checkout at midnight excludes following day');
assert.equal(matches({...stay, check_in: '2026-10-23 17:00:00', check_out: '2026-10-24 02:00:00'}, filters), false, 'Next Vietnam day excluded');
assert.equal(matches({...stay, status: 'CHECKED_OUT'}, filters), false, 'Completed stays hidden');
assert.equal(matches({...stay, status: 'CHECKED_OUT'}, {...filters, showFinished: true}), true, 'History remains available');
assert.equal(matches({...stay, status: 'CHECKED_OUT'}, {...filters, status: 'CHECKED_OUT'}), true, 'Explicit status filter can retrieve finished stays');
assert.equal(isFinished({...stay, status: 'CANCELLED'}), true, 'Cancelled stays belong to hidden history');
for (const payment_status of ['PAID', 'UNPAID', 'REFUNDED']) {
    const cancelled = {...stay, status: 'CANCELLED', payment_status};
    assert.equal(matches(cancelled, filters), false, 'All cancelled stays hidden by default');
    assert.equal(matches(cancelled, {...filters, showFinished: true}), true, 'Cancelled history remains available');
    assert.equal(matches(cancelled, {...filters, status: 'CANCELLED'}), true, 'Explicit cancelled filter reveals history');
}
assert.equal(isFinished({...stay, status: 'CHECKED_OUT', payment_status: 'UNPAID'}), false, 'Uncollected stay remains visible');
assert.equal(tone({...stay, status: 'CANCELLED'}), 'attention');
assert.equal(tone(stay), 'confirmed');
assert.equal(matches(stay, {...filters, query: 'khách', room: 'Mơ'}), true);
assert.equal(matches(stay, {...filters, room: 'Pink'}), false);
console.log('Admin booking filters: OK');
