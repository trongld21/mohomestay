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

const extension = context.renderBookingRow({ ...booking, continued_from_booking_id: 'parent', guest_note: 'Ở tiếp đơn MO-GOC' });
assert.match(extension, /data-continuation-label[^>]*>Ở tiếp đơn MO-GOC</, 'The linked booking is visible on the row');

const eventStart = source.indexOf('    window.addEventListener("mo:booking-created",');
const eventEnd = source.indexOf('    document.addEventListener(', eventStart);
let listener;
let reloaded = false;
const staleFilters = { query: 'Khách cũ', status: 'CHECKED_IN', room: 'Mơ', from: '2026-11-18', to: '2026-11-18', showFinished: false };
vm.runInNewContext(source.slice(eventStart, eventEnd), {
    window: { addEventListener: (_name, handler) => { listener = handler; } },
    bookingFilters: staleFilters,
    bookingPagination: { reset() {} },
    loadBookings: () => { reloaded = true; },
});
listener({ detail: { bookingCode: 'MO-GOI-MOI' } });
assert.deepEqual(staleFilters, { query: 'MO-GOI-MOI', status: 'all', room: 'all', from: '', to: '', showFinished: true });
assert.equal(reloaded, true, 'Reload after selecting the new booking');
