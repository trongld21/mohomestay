((scope) => {
  'use strict';

  function configuredSlots(room) {
    return (room?.packages || []).filter(slot => slot.enabled && slot.mode === 'FIXED_TIME');
  }

  function slotWindow(slot, date) {
    if (!slot || slot.mode !== 'FIXED_TIME' || !date || !slot.checkInTime || !slot.checkOutTime) return null;
    const start = new Date(`${date}T${slot.checkInTime}:00+07:00`);
    const end = new Date(`${date}T${slot.checkOutTime}:00+07:00`);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;
    if (end <= start) end.setTime(end.getTime() + 86400000);
    return { start, end };
  }

  function overlaps(window, interval) {
    return window.start < new Date(interval.end) && window.end > new Date(interval.start);
  }

  function slotState(window, intervals = [], now = new Date()) {
    if (!window || window.start <= now) return 'past';
    return intervals.some(interval => overlaps(window, interval)) ? 'booked' : 'available';
  }

  scope.MoBookingSlots = { configuredSlots, slotWindow, slotState };
})(typeof window === 'undefined' ? globalThis : window);
