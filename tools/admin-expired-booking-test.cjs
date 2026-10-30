const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/admin.js', 'utf8');
const start = source.indexOf('    function renderBookingRow(');
const end = source.indexOf('    async function bookingAction(', start);
assert.ok(start >= 0 && end > start, 'Locate the actual booking row renderer');
const context = vm.createContext({
    LANG_HOME: { abilities: ['bookings.payment'], isSuperAdmin: false },
    bookingSuccessLink: () => '', esc: String, icon: () => '', bookingDate: String, cash: String,
    statusNames: { EXPIRED: 'Hết hạn giữ chỗ' },
    MoBookingFilters: require('../public/assets/admin-booking-filters.js'),
});
vm.runInContext(source.slice(start, end), context);
const booking = { id: 'test', status: 'EXPIRED', payment_status: 'PENDING', booking_source: 'WEBSITE' };
for (const isSuperAdmin of [false, true]) {
    context.LANG_HOME.isSuperAdmin = isSuperAdmin;
    const html = context.renderBookingRow(booking);
    assert.equal((html.match(/data-booking-action="CANCELLED"/g) || []).length, 1, 'Expired requests retain exactly one cancellation action for staff and root');
    assert.ok(html.includes('data-booking-payment='), 'Expired requests retain audited payment recovery');
    assert.ok(html.includes('Hết hạn giữ chỗ'));
}
console.log('Expired booking rows preserve cancellation and payment recovery: OK');
const completed = context.renderBookingRow({ ...booking, status: 'CHECKED_OUT', actual_checkout_at: '2026-10-30 10:09:52', cleaning_until: '2026-10-30 10:39:52' });
assert.ok(completed.includes('Trả thực tế') && completed.includes('Dọn đến'), 'Completed cards must expose the times used to block receipt');
