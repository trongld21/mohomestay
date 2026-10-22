(() => {
    "use strict";
    const root = document.querySelector("#booking-app");
    const input = root?.querySelector('input[name="date"]');
    if (!input) return;
    const label = input.closest("label");
    const field = document.createElement("div");
    field.className = "field manual-date-field";
    label.replaceWith(field);
    input.type = "hidden";
    const symbol = (name) => `<svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${name === "calendar" ? '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 11h18"/>' : `<path d="${name === "left" ? "m15 6-6 6 6 6" : "m9 6 6 6-6 6"}"/>`}</svg>`;
    field.innerHTML = `
        <label for="booking-date-trigger">Ngày ghé Mơ</label>
        <button id="booking-date-trigger" class="manual-date-trigger" type="button" aria-haspopup="dialog" aria-controls="booking-date-popover" aria-expanded="false"><span data-manual-date-label>Chọn ngày</span>${symbol("calendar")}</button>
        <div id="booking-date-popover" class="manual-date-popover" popover="auto" role="dialog" aria-label="Chọn ngày ghé Mơ">
            <div class="manual-calendar-heading">
                <button type="button" data-calendar-previous aria-label="Tháng trước">${symbol("left")}</button>
                <strong data-calendar-title aria-live="polite"></strong>
                <button type="button" data-calendar-next aria-label="Tháng sau">${symbol("right")}</button>
            </div>
            <div class="manual-calendar-weekdays" aria-hidden="true"><span>T2</span><span>T3</span><span>T4</span><span>T5</span><span>T6</span><span>T7</span><span>CN</span></div>
            <div class="manual-calendar-days" data-calendar-days role="group" aria-label="Các ngày trong tháng"></div>
            <footer class="manual-calendar-footer"><span>Giờ Việt Nam · UTC+7</span><button type="button" data-calendar-today>Hôm nay</button></footer>
        </div>`;
    field.prepend(input);
    if (!input.value) {
        const requested = new URLSearchParams(location.search).get("date");
        try {
            MoAdminDatePicker.parse(requested || "");
            input.value = requested;
        } catch {
            input.value = LANG_HOME.today;
        }
    }
    const picker = MoAdminDatePicker.create(root);
    picker.refresh();

    const timeInput = root.querySelector('input[name="time"]');
    const originalTimeField = timeInput.closest("[data-duration-time]");
    // Preserve the element watched by rooms.js when it has already initialized.
    originalTimeField.classList.add("manual-time-field");
    originalTimeField.replaceChildren();
    originalTimeField.innerHTML = `
        <span>Giờ nhận phòng · 24 giờ</span>
        <input name="arrivalHour" type="hidden"><input name="arrivalMinute" type="hidden">
        <div class="manual-time-picker">
            <button type="button" data-time-trigger="hour" aria-haspopup="dialog" aria-controls="manual-time-popover" aria-expanded="false"><span data-time-hour-label>Chọn giờ</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
            <span aria-hidden="true">:</span>
            <button type="button" data-time-trigger="minute" aria-haspopup="dialog" aria-controls="manual-time-popover" aria-expanded="false"><span data-time-minute-label>Phút</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
        </div>
        <div id="manual-time-popover" class="manual-time-popover" popover="auto" role="dialog" aria-label="Chọn giờ nhận phòng 24 giờ">
            <section class="manual-time-column"><h4 id="booking-hour-title">Chọn giờ</h4><div class="manual-hour-options" role="listbox" aria-labelledby="booking-hour-title" data-time-hours></div></section>
            <section class="manual-time-column manual-minute-column"><h4 id="booking-minute-title">Chọn phút</h4><div class="manual-minute-options" role="listbox" aria-labelledby="booking-minute-title" data-time-minutes></div><label class="manual-exact-minute">Phút chính xác<input type="number" min="0" max="59" step="1" inputmode="numeric" data-time-exact aria-label="Phút chính xác từ 0 đến 59"></label></section>
        </div>`;
    timeInput.type = "hidden";
    originalTimeField.prepend(timeInput);
    root.querySelector(".booking-primary-fields").append(originalTimeField);
    const timePicker = MoTimePicker.create(root);
    function refreshTime() {
        if (timeInput.value) timePicker.set(timeInput.value);
        else {
            root.querySelector("[data-time-hour-label]").textContent = "Chọn giờ";
            root.querySelector("[data-time-minute-label]").textContent = "Phút";
        }
    }
    refreshTime();
    timeInput.addEventListener("mo:time-sync", refreshTime);
})();
