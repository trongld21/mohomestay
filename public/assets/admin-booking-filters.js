((root) => {
    const timestamp = value => Date.parse(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
    function needsAttention(item) {
        return item.status === 'CANCELLED' && item.payment_status === 'PAID'
            || item.status === 'CHECKED_OUT' && ['UNPAID', 'PENDING', 'FAILED'].includes(item.payment_status);
    }
    function isFinished(item) {
        return ['CANCELLED', 'EXPIRED'].includes(item.status)
            || item.status === 'CHECKED_OUT' && !needsAttention(item);
    }
    function matches(item, filters) {
        if (!filters.showFinished && filters.status === 'all' && isFinished(item)) return false;
        if (filters.status !== 'all' && item.status !== filters.status) return false;
        if (filters.room !== 'all' && item.room_name !== filters.room) return false;
        if (![item.guest_name, item.guest_phone, item.booking_code].join(' ').toLocaleLowerCase('vi')
            .includes(filters.query.trim().toLocaleLowerCase('vi'))) return false;
        // Include any stay overlapping the selected Vietnam calendar days, with exclusive checkout.
        if (filters.from && timestamp(item.check_out) <= Date.parse(filters.from + 'T00:00:00+07:00')) return false;
        if (filters.to && timestamp(item.check_in) >= Date.parse(filters.to + 'T00:00:00+07:00') + 86400000) return false;
        return true;
    }
    function tone(item) {
        if (needsAttention(item)) return 'attention';
        if (item.status === 'CHECKED_IN' && item.isOverdue === true) return 'overdue';
        return ({PENDING: 'pending', CONFIRMED: 'confirmed', CHECKED_IN: 'staying'})[item.status] || 'finished';
    }
    const api = {matches, isFinished, needsAttention, tone};
    if (typeof module !== 'undefined') module.exports = api;
    else root.MoBookingFilters = api;
})(typeof window !== 'undefined' ? window : globalThis);
