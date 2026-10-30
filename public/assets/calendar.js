(() => {
    "use strict";

    const root = document.querySelector("#calendar-app");
    if (!root || !window.MoBookingSlots) return;
    const one = (selector) => root.querySelector(selector);
    const rooms = (window.LANG_HOME?.rooms || []).filter((room) => room.status === "ACTIVE");
    const query = new URLSearchParams(location.search);
    const roomSelect = one("[data-cal-room]");
    const packageSelect = one("[data-cal-package]");
    const time = one("[data-cal-time]");
    const timeField = one("[data-cal-time-field]");
    const timePicker = window.MoTimePicker.create(root, {
        minuteStep: 30,
        root: timeField.querySelector(".manual-time-picker"),
        panel: one("#calendar-time-popover"),
        fields: {
            time,
            arrivalHour: one("[data-cal-hour]"),
            arrivalMinute: one("[data-cal-minute]"),
        },
    });
    const grid = one("[data-calendar-grid]");
    const output = one("[data-availability]");
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char]);
    const localDate = (value) => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit" }).format(value);
    const validDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value || "") && !Number.isNaN(Date.parse(value + "T00:00:00+07:00")) && localDate(new Date(value + "T00:00:00+07:00")) === value;
    let date = validDate(query.get("date")) ? query.get("date") : LANG_HOME.today;
    let month = date.slice(0, 7);
    let snapshot = null;
    let monthSnapshot = null;
    let clockOffset = 0;
    let timer;
    let version = 0;
    const now = () => new Date(Date.now() + clockOffset);
    const selectedRoom = () => rooms.find((room) => room.id === roomSelect.value) || rooms[0];
    const selectedPackage = () => {
        const room = selectedRoom();
        const slot = MoBookingSlots.configuredSlots(room).find((pkg) => pkg.id === packageSelect.value);
        return slot ? MoBookingSlots.priceForArrival(room, slot, date) : null;
    };
    const labels = { available: "Còn trống", booked: "Đã có lịch", past: "Đã qua giờ", unknown: "Chưa kiểm tra", inactive: "Tạm ngưng" };

    function state(day, data) {
        const info = data?.rooms.find((room) => room.id === selectedRoom()?.id);
        if (!info) return "unknown";
        if (!info.active) return "inactive";
        const window = MoBookingSlots.slotWindow(selectedPackage(), day, time.value, now());
        if (!window) return "unknown";
        return MoBookingSlots.slotState(window, info.intervals, now());
    }

    function updateTime() {
        const pkg = selectedPackage();
        const fixed = pkg?.mode === "FIXED_TIME";
        timeField.hidden = fixed;
        time.disabled = fixed;
        if (fixed) time.value = pkg.checkInTime;
        timePicker.set(time.value || "14:00");
        timePicker.setDisabled(fixed);
    }

    function updatePackages(initial = false) {
        const previous = packageSelect.value;
        const room = selectedRoom();
        const list = MoBookingSlots.configuredSlots(room).map((slot) => MoBookingSlots.priceForArrival(room, slot, date));
        packageSelect.innerHTML = list.length ? list.map((pkg) => {
            const timing = pkg.mode === "FIXED_TIME" ? `${pkg.checkInTime} – ${pkg.checkOutTime}` : `${pkg.durationMinutes / 60} giờ`;
            return `<option value="${escape(pkg.id)}">${escape(pkg.name)} · ${escape(timing)} · ${Number(pkg.price).toLocaleString("vi-VN")}đ</option>`;
        }).join("") : '<option value="">Chưa có gói đặt online</option>';
        if (initial && list.some((pkg) => pkg.id === query.get("package"))) packageSelect.value = query.get("package");
        else if (list.some((pkg) => pkg.id === previous)) packageSelect.value = previous;
        updateTime();
    }

    function draw() {
        const focused = document.activeElement?.dataset.date;
        const [year, number] = month.split("-").map(Number);
        const offset = (new Date(year, number - 1, 1).getDay() + 6) % 7;
        const days = new Date(year, number, 0).getDate();
        const today = localDate(now());
        const limit = localDate(new Date(now().getTime() + 366 * 86400000));
        one("[data-month-title]").textContent = `Tháng ${number}, ${year}`;
        one("[data-selected-title]").textContent = "Ngày " + date.split("-").reverse().join("/");
        grid.innerHTML = ["Thứ 2", "Thứ 3", "Thứ 4", "Thứ 5", "Thứ 6", "Thứ 7", "CN"].map((day) => `<span class="weekday">${day}</span>`).join("")
            + Array.from({ length: Math.ceil((offset + days) / 7) * 7 }, (_, index) => {
                const day = index - offset + 1;
                if (day < 1 || day > days) return '<span aria-hidden="true"></span>';
                const value = `${month}-${String(day).padStart(2, "0")}`;
                const disabled = value < today || value > limit;
                const status = disabled ? "past" : state(value, monthSnapshot);
                return `<button type="button" class="day${value === date ? " selected" : ""}" data-date="${value}" data-availability-state="${status}" aria-label="${day}/${number}/${year} · ${labels[status]}" aria-pressed="${value === date}" ${disabled ? "disabled" : ""}><span>${day}</span><i aria-hidden="true"></i></button>`;
            }).join("");
        if (focused) grid.querySelector(`[data-date="${focused}"]`)?.focus();
    }

    function render() {
        updatePackages();
        const room = selectedRoom();
        const pkg = selectedPackage();
        const status = state(date, snapshot);
        const window = MoBookingSlots.slotWindow(pkg, date, time.value, now());
        const format = (value) => value.toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", day: "2-digit", month: "2-digit", year: "numeric" });
        output.innerHTML = room ? `<article>
            <div class="calendar-room-preview"><img src="${escape(room.image)}" alt="Phòng ${escape(room.name)}"><div>
                <span class="status-chip ${status === "available" ? "free" : status === "booked" ? "busy" : ""}">${labels[status]}</span>
                <h3>${escape(room.name)}</h3><p class="calendar-room-subtitle">${escape(room.subtitle)}</p>
                <p class="calendar-package">${escape(pkg?.name || "Chưa có gói đặt online")} ${pkg ? "· " + Number(pkg.price).toLocaleString("vi-VN") + "đ" : ""}</p>
            </div></div>
            ${window ? `<div class="calendar-time-summary"><div><span>Thời gian</span><p>${escape(format(window.start))} → ${escape(format(window.end))}</p></div></div>` : '<p class="notice">Chọn gói và giờ nhận phòng để kiểm tra lịch.</p>'}
            ${status === "available" && snapshot.onlineBooking ? `<a class="button calendar-book-button" href="/bookings?${new URLSearchParams({ room: room.id, package: pkg.id, date, time: time.value })}">Đặt khung giờ này →</a>` : `<a class="button calendar-book-button" href="https://zalo.me/0357907153" target="_blank" rel="noopener noreferrer">${status === "available" ? "Còn trống · Nhắn Mơ để đặt" : "Nhắn Mơ tư vấn ↗"}</a>`}
        </article>` : '<div class="empty-state">Chưa có phòng đang mở.</div>';
        draw();
    }

    async function fetchRange(start, days) {
        const response = await fetch(`/api/availability?date=${start}&days=${days}`, { cache: "no-store", signal: AbortSignal.timeout(10000) });
        const data = await response.json();
        if (!response.ok || data.connected !== true || !Array.isArray(data.rooms)) throw new Error(data.error || "Chưa tải được lịch phòng.");
        return data;
    }

    async function load(background = false) {
        clearTimeout(timer);
        const current = ++version;
        const selectedDate = date;
        const selectedMonth = month;
        if (!background) {
            snapshot = null;
            monthSnapshot = null;
            draw();
            output.innerHTML = '<p class="text-sm text-[#736b61]" role="status">Đang kiểm tra lịch phòng…</p>';
        }
        output.setAttribute("aria-busy", "true");
        try {
            const monthRequest = fetchRange(selectedMonth + "-01", 62);
            const [monthData, selectedData] = await Promise.all([monthRequest, selectedDate.startsWith(selectedMonth) ? monthRequest : fetchRange(selectedDate, 31)]);
            if (current !== version) return;
            monthSnapshot = monthData;
            snapshot = selectedData;
            clockOffset = Date.parse(selectedData.serverTime) - Date.now();
            if (!Number.isFinite(clockOffset)) clockOffset = 0;
            render();
        } catch (error) {
            if (current !== version) return;
            snapshot = null;
            monthSnapshot = null;
            draw();
            output.innerHTML = `<p class="notice error">${escape(error.message)} Lịch sẽ tự thử lại; bạn cũng có thể bấm tải lại phía trên.</p>`;
        } finally {
            if (current === version) {
                output.setAttribute("aria-busy", "false");
                if (!document.hidden) timer = setTimeout(() => load(true), 15000);
            }
        }
    }

    grid.addEventListener("click", (event) => {
        const button = event.target.closest("[data-date]");
        if (!button || button.disabled) return;
        date = button.dataset.date;
        load();
    });
    function shiftMonth(delta) {
        const [year, number] = month.split("-").map(Number);
        const value = new Date(year, number - 1 + delta, 1);
        month = `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, "0")}`;
        load();
    }
    one("[data-month-prev]").onclick = () => shiftMonth(-1);
    one("[data-month-next]").onclick = () => shiftMonth(1);
    one("[data-refresh]").onclick = () => load();
    roomSelect.onchange = () => { updatePackages(); load(); };
    packageSelect.onchange = () => { updateTime(); load(); };
    time.onchange = () => load();
    document.addEventListener("visibilitychange", () => {
        clearTimeout(timer);
        if (!document.hidden) load(true);
    });
    window.addEventListener("focus", () => load(true));
    if (rooms.some((room) => room.id === query.get("room"))) roomSelect.value = query.get("room");
    if (/^(?:[01]\d|2[0-3]):(?:00|30)$/.test(query.get("time") || "")) time.value = query.get("time");
    updatePackages(true);
    load();
})();
