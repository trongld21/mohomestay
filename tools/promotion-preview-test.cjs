const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/rooms.js', 'utf8');
const start = source.indexOf('        function invalidatePrice(');
assert.ok(start >= 0, 'public booking invalidates pending and stale price quotes');
const end = source.indexOf('        function syncGuests()', start);
const context = { priceRevision: 0, priceQuote: { price: 100 }, priceError: 'old', priceTimer: null, clearTimeout, setTimeout, renderSlots() {}, renderSummary() {}, selectedSlot: () => null };
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\n invalidatePrice();', context);
assert.equal(context.priceQuote, null);
assert.equal(context.priceError, '');
assert.equal(context.priceRevision, 1);
async function checkAsyncPreviews() {
    const pending = [];
    Object.assign(context, {
        selectedSlot: () => ({ id: 'day' }),
        selectedWindow: () => ({ start: new Date() }),
        room: () => ({ id: 'room' }),
        LANG_HOME: { csrf: 'token' },
        form: { date: { value: '2026-11-02' }, time: { value: '08:00' }, elements: {
            guestName: { value: 'Khách cũ' }, guestPhone: { value: '0901234567' }, guestEmail: { value: 'guest@example.com' },
        } },
        request: () => new Promise((resolve, reject) => pending.push({ resolve, reject })),
    });
    const old = vm.runInContext('refreshPrice(1)', context);
    context.priceRevision = 2;
    pending.shift().resolve({ price: 90000, basePrice: 100000, percent: 10 });
    await old;
    assert.equal(context.priceQuote, null, 'outdated quote never returns a discount');
    const fresh = vm.runInContext('refreshPrice(2)', context);
    pending.shift().resolve({ price: 80000, basePrice: 100000, percent: 20 });
    await fresh;
    assert.equal(context.priceQuote.price, 80000);
    const failed = vm.runInContext('refreshPrice(2)', context);
    pending.shift().reject(new Error('Price unavailable'));
    await failed;
    assert.equal(context.priceQuote, null, 'pricing errors discard the discount');
    assert.equal(context.priceError, 'Price unavailable');

    const manual = fs.readFileSync('public/assets/manual-booking.js', 'utf8');
    const manualStart = manual.indexOf('    async function updateAvailability()');
    const manualEnd = manual.indexOf('    function closeDialog()', manualStart);
    const fields = Object.fromEntries(['agreedPrice', 'date', 'time', 'guestName', 'guestPhone', 'guestEmail'].map((name) => [name, { value: '' }]));
    fields.date.value = '2026-11-02';
    fields.time.value = '08:00';
    fields.agreedPrice.value = '100000';
    const state = {
        clearTimeout, contactTimer: null, revision: 0, available: false,
        submit: { disabled: false }, selectedRoom: () => ({ id: 'room', name: 'Room' }),
        selectedPackage: () => ({ id: 'day', name: 'Day', mode: 'DURATION' }),
        updateMode() {}, isCustom: () => false, timePicker: { setDisabled() {} },
        fields, allowAnyDate: false, summary: { dataset: {} }, escape: String,
        dateTime: String, cash: String, priceEdited: false, dialog: { open: true }, saving: false,
        MoBookingSlots: {
            slotWindow: () => ({ start: new Date('2026-11-02T08:00:00+07:00'), end: new Date('2026-11-02T10:00:00+07:00') }),
            slotState: () => 'available',
        },
        request: (url) => url === '/api/booking-price'
            ? new Promise((resolve) => pending.push({ resolve }))
            : Promise.resolve({ connected: true, rooms: [{ id: 'room', active: true, intervals: [] }] }),
    };
    vm.createContext(state);
    state.MoBookingSlots.manualSlotWindow = state.MoBookingSlots.slotWindow;
    vm.runInContext(manual.slice(manualStart, manualEnd), state);
    const quote = vm.runInContext('updateAvailability()', state);
    assert.equal(state.submit.disabled, true, 'manual form blocks while quote is pending');
    fields.agreedPrice.value = '77777';
    state.priceEdited = true;
    pending.shift().resolve({ price: 80000, basePrice: 100000, percent: 20 });
    await quote;
    assert.equal(fields.agreedPrice.value, '77777', 'typing agreed price during request survives response');
    assert.equal(state.available, true);
    assert.equal(state.submit.disabled, false);
    assert.match(state.summary.innerHTML, /77777/, 'summary uses the latest agreed price');
    const outdated = vm.runInContext('updateAvailability()', state);
    state.revision++;
    fields.agreedPrice.value = '66666';
    pending.shift().resolve({ price: 70000, basePrice: 100000, percent: 30 });
    await outdated;
    assert.equal(fields.agreedPrice.value, '66666', 'stale manual quote never changes agreed price');
    assert.equal(state.submit.disabled, true, 'stale manual quote cannot enable submission');
    state.request = async () => { throw new Error('Price unavailable'); };
    await vm.runInContext('updateAvailability()', state);
    assert.equal(state.available, false, 'quote failure blocks manual booking');
    assert.equal(state.submit.disabled, true);
    assert.equal(fields.agreedPrice.value, '66666', 'errors preserve the explicit agreed price');
    state.isCustom = () => true;
    fields.startNow = { checked: false };
    state.customWindow = state.MoBookingSlots.slotWindow;
    state.request = async (url) => {
        assert.notEqual(url, '/api/booking-price', 'custom bookings have no automatic package discount');
        return { connected: true, rooms: [{ id: 'room', active: true, intervals: [] }] };
    };
    await vm.runInContext('updateAvailability()', state);
    assert.equal(fields.agreedPrice.value, '66666');
    assert.equal(state.submit.disabled, false);
    const payloadStart = manual.indexOf('        const payload = Object.fromEntries(new FormData(form));');
    const payloadEnd = manual.indexOf('        saving = true;', payloadStart);
    for (const [custom, edited, expected] of [[false, false, undefined], [false, true, '66666'], [true, false, '66666']]) {
        const payloadContext = vm.createContext({
            FormData: class {
                *[Symbol.iterator]() {
                    yield* Object.entries({ agreedPrice: '66666', numberOfGuests: '2' });
                }
            },
            form: {}, fields, isCustom: () => custom, priceEdited: edited,
            endTime: () => '10:00', requestId: 'request',
        });
        vm.runInContext(manual.slice(payloadStart, payloadEnd) + '\n globalThis.result = payload;', payloadContext);
        assert.equal(payloadContext.result.agreedPrice, expected, 'only edited or custom bookings submit an agreed price');
    }
}

checkAsyncPreviews().then(() => console.log('Promotion preview invalidation, races and agreed prices OK')).catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
