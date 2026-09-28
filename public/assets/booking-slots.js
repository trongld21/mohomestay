((scope) => {
  'use strict';

  function configuredSlots(room) {
    return (room?.packages || []).filter(slot => slot.enabled && ['FIXED_TIME', 'DURATION'].includes(slot.mode));
  }

  function slotWindow(slot, date, time = '') {
    if (!slot || !date) return null;
    const startTime = slot.mode === 'FIXED_TIME' ? slot.checkInTime : time;
    if (!startTime) return null;
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
