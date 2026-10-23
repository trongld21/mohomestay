(() => {
    "use strict";

    if (window.LANG_HOME?.manualDateOverride !== true) return;
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[char]);
    const localValue = (value) => new Date(Date.parse(value.replace(" ", "T") + "Z") + 7 * 3600000).toISOString().slice(0, 19);
    const dateLabel = (value) => new Intl.DateTimeFormat("vi-VN", {
        timeZone: "Asia/Ho_Chi_Minh", dateStyle: "short", timeStyle: "short",
    }).format(new Date(value.replace(" ", "T") + "Z"));
    const fieldClass = "mt-1 min-h-11 w-full rounded-lg border border-[#cdbba8] bg-white px-3 py-2 text-sm text-[#463a30] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]";
    const buttonClass = "min-h-11 rounded-lg border border-[#b89d85] px-4 py-2 text-sm font-semibold enabled:hover:bg-[#efe5d8] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e] disabled:cursor-wait disabled:opacity-60";
    const icon = (name) => `<svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-${name}"></use></svg>`;

    function dateTimeField(name, label, value) {
        const local = localValue(value);
        const prefix = `stay-${name}`;
        return `<section class="grid min-w-0 gap-3" data-stay-datetime="${name}">
            <h3 class="font-sans text-sm font-semibold">${label}</h3>
            <input name="${name}" type="hidden" value="${escape(local)}">
            <div class="manual-date-field">
                <input name="date" type="hidden" value="${local.slice(0, 10)}">
                <button id="${prefix}-date-trigger" class="manual-date-trigger" type="button" aria-label="${label}: chọn ngày" aria-haspopup="dialog" aria-controls="${prefix}-date-panel" aria-expanded="false"><span data-manual-date-label>Chọn ngày</span>${icon("calendar")}</button>
                <div id="${prefix}-date-panel" class="manual-date-popover" popover="auto" role="dialog" aria-label="Chọn ngày ${label.toLowerCase()}">
                    <div class="manual-calendar-heading">
                        <button type="button" data-calendar-previous aria-label="Tháng trước">${icon("arrow-left")}</button>
                        <strong data-calendar-title aria-live="polite"></strong>
                        <button type="button" data-calendar-next aria-label="Tháng sau">${icon("arrow-right")}</button>
                    </div>
                    <div class="manual-calendar-weekdays" aria-hidden="true"><span>T2</span><span>T3</span><span>T4</span><span>T5</span><span>T6</span><span>T7</span><span>CN</span></div>
                    <div class="manual-calendar-days" data-calendar-days role="group" aria-label="Các ngày trong tháng"></div>
                    <footer class="manual-calendar-footer"><span>Giờ Việt Nam · UTC+7</span><button type="button" data-calendar-today>Hôm nay</button></footer>
                </div>
            </div>
            <div class="manual-time-field">
                <input type="hidden" data-stay-time><input type="hidden" data-stay-hour><input type="hidden" data-stay-minute>
                <div class="manual-time-picker">
                    <button type="button" data-time-trigger="hour" aria-label="${label}: chọn giờ" aria-haspopup="dialog" aria-controls="${prefix}-time-panel" aria-expanded="false"><span data-time-hour-label></span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                    <span aria-hidden="true">:</span>
                    <button type="button" data-time-trigger="minute" aria-label="${label}: chọn phút" aria-haspopup="dialog" aria-controls="${prefix}-time-panel" aria-expanded="false"><span data-time-minute-label></span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                </div>
                <div id="${prefix}-time-panel" class="manual-time-popover" popover="auto" role="dialog" aria-label="Giờ ${label.toLowerCase()} · 24 giờ">
                    <section class="manual-time-column"><h4 id="${prefix}-hour-title">Chọn giờ</h4><div class="manual-hour-options" role="listbox" aria-labelledby="${prefix}-hour-title" data-time-hours></div></section>
                    <section class="manual-time-column manual-minute-column"><h4 id="${prefix}-minute-title">Chọn phút</h4><div class="manual-minute-options" role="listbox" aria-labelledby="${prefix}-minute-title" data-time-minutes></div><label class="manual-exact-minute">Phút chính xác<input type="number" min="0" max="59" step="1" inputmode="numeric" data-time-exact aria-label="Phút chính xác từ 0 đến 59"></label></section>
                </div>
            </div>
        </section>`;
    }

    document.addEventListener("mo:edit-booking-stay", (event) => {
        if (document.querySelector("[data-booking-stay-dialog]")) return;
        const { booking, trigger } = event.detail;
        const rooms = LANG_HOME.rooms.filter((room) => room.status === "ACTIVE" || room.id === booking.room_id);
        const dialog = document.createElement("dialog");
        dialog.dataset.bookingStayDialog = "";
        dialog.className = "m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-xl overflow-auto rounded-2xl border border-[#e5dacb] bg-[#fffdfa] p-5 text-[#463a30] shadow-xl backdrop:bg-black/40 sm:p-6";
        dialog.setAttribute("aria-labelledby", "booking-stay-title");
        dialog.innerHTML = `
            <form class="grid gap-4">
                <div><h2 id="booking-stay-title" class="text-xl font-semibold">Đổi phòng / sửa lịch</h2>
                    <p class="mt-1 text-sm text-[#736b61]">${escape(booking.booking_code)} · ${escape(booking.guest_name)}</p></div>
                <div class="rounded-xl border border-[#e5dacb] bg-[#f6efe6] p-3 text-sm">
                    <p>Hiện tại: <strong>${escape(booking.room_name)}</strong></p>
                    <p class="mt-1">${escape(dateLabel(booking.check_in))} → ${escape(dateLabel(booking.check_out))}</p>
                    <p class="mt-2 font-semibold">Giữ nguyên giá: ${new Intl.NumberFormat("vi-VN").format(Number(booking.total_price))}đ</p>
                </div>
                <label class="text-sm font-medium">Phòng mới
                    <select name="roomId" class="${fieldClass}" required>${rooms.map((room) => `<option value="${escape(room.id)}"${room.id === booking.room_id ? " selected" : ""}>${escape(room.name)} · tối đa ${Number(room.maxGuests)} khách</option>`).join("")}</select>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    ${dateTimeField("checkIn", "Ngày giờ nhận phòng", booking.check_in)}
                    ${dateTimeField("checkOut", "Ngày giờ trả phòng", booking.check_out)}
                </div>
                <label class="text-sm font-medium">Lý do điều chỉnh<textarea name="reason" minlength="5" maxlength="500" rows="3" class="${fieldClass}" placeholder="Ví dụ: Khách yêu cầu chuyển từ Mơ sang Black" required></textarea></label>
                <div data-stay-error role="alert" tabindex="-1" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 focus:outline-2 focus:outline-offset-2 focus:outline-red-700" hidden>
                    <p class="font-semibold" data-stay-error-title>Chưa lưu được thay đổi</p>
                    <p class="mt-1 leading-6" data-stay-error-message></p>
                    <p class="mt-2 text-xs leading-5" data-stay-error-hint></p>
                </div>
                <div class="flex flex-wrap justify-end gap-3 border-t border-[#e5dacb] pt-4">
                    <button type="button" data-stay-cancel class="${buttonClass}">Hủy</button>
                    <button type="submit" class="${buttonClass} border-[#79543e] bg-[#79543e] text-white enabled:hover:bg-[#63412b]">Lưu thay đổi</button>
                </div>
            </form>`;
        document.body.append(dialog);
        const form = dialog.querySelector("form");
        const error = dialog.querySelector("[data-stay-error]");
        const events = new AbortController();
        const datePickers = [];
        for (const section of dialog.querySelectorAll("[data-stay-datetime]")) {
            const name = section.dataset.stayDatetime;
            const original = localValue(name === "checkIn" ? booking.check_in : booking.check_out);
            const date = section.querySelector('[name="date"]');
            const time = section.querySelector("[data-stay-time]");
            const datePicker = MoAdminDatePicker.create(section, { signal: events.signal });
            datePickers.push(datePicker);
            datePicker.refresh();
            const timePicker = MoTimePicker.create(dialog, {
                root: section.querySelector(".manual-time-picker"),
                panel: section.querySelector(".manual-time-popover"),
                fields: { time, arrivalHour: section.querySelector("[data-stay-hour]"), arrivalMinute: section.querySelector("[data-stay-minute]") },
                signal: events.signal,
            });
            timePicker.set(original.slice(11, 16));
            const sync = () => {
                const seconds = time.value === original.slice(11, 16) ? original.slice(17, 19) : "00";
                form.elements[name].value = `${date.value}T${time.value}:${seconds}`;
            };
            date.addEventListener("change", sync);
            time.addEventListener("change", sync);
        }
        let saving = false;
        dialog.querySelector("[data-stay-cancel]").onclick = () => { if (!saving) dialog.close(); };
        dialog.addEventListener("cancel", (cancelEvent) => { if (saving) cancelEvent.preventDefault(); });
        dialog.addEventListener("close", () => {
            datePickers.forEach((picker) => picker.close());
            events.abort();
            dialog.remove();
            if (trigger?.isConnected) trigger.focus();
        }, { once: true });
        form.onsubmit = async (submitEvent) => {
            submitEvent.preventDefault();
            if (saving || !form.reportValidity()) return;
            const payload = Object.fromEntries(["roomId", "checkIn", "checkOut", "reason"].map((name) => [name, form.elements[name].value]));
            Object.assign(payload, {
                expectedRoomId: booking.room_id,
                expectedCheckIn: booking.check_in,
                expectedCheckOut: booking.check_out,
            });
            saving = true;
            error.hidden = true;
            form.querySelectorAll("button").forEach((button) => { button.disabled = true; });
            let rejected = false;
            try {
                const response = await fetch(`/api/admin/bookings/${encodeURIComponent(booking.id)}/stay`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();
                if (!response.ok) {
                    rejected = response.status >= 400 && response.status < 500;
                    throw new Error(data.error || "Máy chủ chưa xử lý được yêu cầu. Vui lòng thử lại.");
                }
                dialog.close();
                LangUI.toast({ type: "success", title: "Đã đổi phòng / sửa lịch", message: `${booking.booking_code} · Giữ nguyên giá và thanh toán.` });
                document.dispatchEvent(new CustomEvent("mo:booking-stay-saved"));
            } catch (failure) {
                const message = failure instanceof SyntaxError
                    ? "Máy chủ trả về dữ liệu không hợp lệ. Vui lòng tải lại danh sách để kiểm tra đơn."
                    : failure instanceof TypeError
                        ? "Mất kết nối với máy chủ. Vui lòng tải lại danh sách để kiểm tra đơn trước khi thử lại."
                        : failure.message || "Không kết nối được máy chủ. Vui lòng thử lại.";
                error.querySelector("[data-stay-error-title]").textContent = rejected ? "Không thể lưu thay đổi" : "Chưa xác nhận được kết quả lưu";
                error.querySelector("[data-stay-error-message]").textContent = message;
                error.querySelector("[data-stay-error-hint]").textContent = rejected
                    ? "Đơn chưa thay đổi. Thông tin bạn vừa nhập vẫn được giữ để điều chỉnh và thử lại."
                    : "Thông tin vừa nhập vẫn được giữ. Hãy kiểm tra trạng thái đơn trước khi gửi lại yêu cầu.";
                error.hidden = false;
                error.focus({ preventScroll: true });
                error.scrollIntoView({ block: "nearest", behavior: "instant" });
                LangUI.toast({ type: "error", title: "Chưa lưu được thay đổi", message });
            } finally {
                saving = false;
                form.querySelectorAll("button").forEach((button) => { button.disabled = false; });
            }
        };
        dialog.showModal();
    });
})();
