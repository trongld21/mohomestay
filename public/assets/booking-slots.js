((scope) => {
    'use strict';

    function configuredSlots(room) {
        return (room?.packages || []).filter(slot => slot.enabled && ['FIXED_TIME', 'DURATION'].includes(slot.mode));
    }

    function priceForArrival(room, slot, date) {
        const base = Number(slot.basePrice ?? slot.price);
        const own = slot.discountPercent !== null && slot.discountPercent !== undefined;
        let percent = Number(own ? slot.discountPercent : (room?.discountPercent || 0));
        const start = own ? slot.discountStart : room?.discountStart;
        const end = own ? slot.discountEnd : room?.discountEnd;
        if (!date || (start && date < start) || (end && date > end)) percent = 0;
        return { ...slot, basePrice: base, effectiveDiscountPercent: percent,
            price: percent === 0 ? base : Math.round(base * (100 - percent) / 100000) * 1000 };
    }

    function earliestBookingDate(room, now = new Date()) {
        const today = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(now);
        const yesterday = new Date(`${today}T12:00:00+07:00`);
        yesterday.setTime(yesterday.getTime() - 86400000);
        const previousDate = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(yesterday);
        const hasNight = configuredSlots(room).some(slot =>
            slot.mode === 'FIXED_TIME' && slot.checkOutTime < slot.checkInTime);
        return hasNight ? previousDate : today;
    }

    function slotWindow(slot, date, time = '', now = new Date()) {
        if (!slot || !date) return null;
        const startTime = slot.mode === 'FIXED_TIME' ? slot.checkInTime : time;
        if (!/^(?:[01]\d|2[0-3]):(?:00|30)$/.test(startTime || '')) return null;
        const start = new Date(`${date}T${startTime}:00+07:00`);
        let end;
        if (slot.mode === 'FIXED_TIME' && slot.checkOutTime) {
            end = new Date(`${date}T${slot.checkOutTime}:00+07:00`);
        } else if (slot.mode === 'DURATION' && Number(slot.durationMinutes) >= 30) {
            end = new Date(start.getTime() + Number(slot.durationMinutes) * 60000);
        } else {
            return null;
        }
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;
        if (end <= start) end.setTime(end.getTime() + 86400000);
        const fixed = slot.mode === 'FIXED_TIME';
        const overnight = fixed && slot.checkOutTime < slot.checkInTime;
        if (fixed && !overnight && start <= now && end > now) {
            start.setTime(Math.ceil(now.getTime() / 1800000) * 1800000);
        }
        return { start, end, fixed };
    }

    function extendedWindow(window, hours = 0) {
        const count = Number(hours);
        if (!window || !Number.isInteger(count) || count < 0 || count > 2) return null;
        return { ...window, end: new Date(window.end.getTime() + count * 3600000) };
    }

    function overlaps(window, interval) {
        const needsCleaning = ['CONFIRMED', 'CHECKED_IN', 'PENDING'].includes(interval.status);
        const occupiedEnd = new Date(window.end.getTime() + (needsCleaning ? 1800000 : 0));
        return window.start < new Date(interval.end) && occupiedEnd > new Date(interval.start);
    }

    function slotState(window, intervals = [], now = new Date()) {
        if (!window || window.end <= now || window.end <= window.start || (!window.fixed && window.start <= now)) return 'past';
        const remaining = window;
        return intervals.some(interval => overlaps(remaining, interval)) ? 'booked' : 'available';
    }

    function durationDayState(slot, date, intervals = [], now = new Date()) {
        const dayStart = new Date(`${date}T00:00:00+07:00`).getTime();
        const dayEnd = dayStart + 86400000;
        const first = Math.max(dayStart, (Math.floor(now.getTime() / 1800000) + 1) * 1800000);
        if (first >= dayEnd) return 'past';
        for (let start = first; start < dayEnd; start += 1800000) {
            const window = { start: new Date(start), end: new Date(start + Number(slot.durationMinutes) * 60000), fixed: false };
            if (slotState(window, intervals, now) === 'available') return 'available';
        }
        return 'booked';
    }

    scope.MoBookingSlots = { configuredSlots, priceForArrival, earliestBookingDate, slotWindow, extendedWindow, slotState, durationDayState };
})(typeof window === 'undefined' ? globalThis : window);
