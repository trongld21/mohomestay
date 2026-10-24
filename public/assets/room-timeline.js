(() => {
    "use strict";

    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[c]);
    const timestamp = (value) => new Date(value).getTime();
    const utc = (value) => timestamp(value.replace(" ", "T") + "Z");
    const localStamp = (value) => new Date(timestamp(value) + 7 * 3600000).toISOString().slice(0, 16);
    const dateTime = (value) => new Intl.DateTimeFormat("vi-VN", {
        timeZone: "Asia/Ho_Chi_Minh", dateStyle: "short", timeStyle: "short",
    }).format(new Date(value));
    const cash = (value) => new Intl.NumberFormat("vi-VN").format(value) + "đ";
    const overlaps = (start, end, interval) => start < timestamp(interval.end) && end > timestamp(interval.start);
    const searchOverlaps = (start, end, interval) => overlaps(start, end +
        (["CONFIRMED", "CHECKED_IN", "PENDING"].includes(interval.status) ? 3600000 : 0), interval);

    function freeWindows(room, start, end) {
        if (!room.active || end <= start) return [];
        const busy = room.intervals.filter((i) => searchOverlaps(start, end, i))
            .map((i) => [Math.max(start, timestamp(i.start) - (["CONFIRMED", "CHECKED_IN", "PENDING"].includes(i.status) ? 3600000 : 0)), Math.min(end, timestamp(i.end))])
            .sort((a, b) => a[0] - b[0]);
        const result = [];
        let cursor = start;
        busy.forEach(([a, b]) => {
            if (a > cursor) result.push([cursor, a]);
            cursor = Math.max(cursor, b);
        });
        if (cursor < end) result.push([cursor, end]);
        return result;
    }

    function cellState(room, start, end, now) {
        if (!room.active) return "inactive";
        const busy = room.intervals.filter((i) => overlaps(start, end, i));
        if (busy.some((i) => i.status === "BLOCKED")) return "blocked";
        if (busy.some((i) => i.status === "CLEANING")) return "cleaning";
        if (busy.some((i) => i.status === "HELD")) return "held";
        if (busy.length) return "booked";
        return end <= now ? "past" : "free";
    }

    window.MoRoomTimeline = { freeWindows, cellState, overlaps };
    const labels = { free: "Trống", booked: "Đã đặt", blocked: "Đang khóa", past: "Đã qua", inactive: "Ngưng nhận", cleaning: "Dọn phòng", held: "Đang giữ" };
    document.querySelectorAll("[data-room-timeline]").forEach((root) => {
        const one = (s) => root.querySelector(s);
        const staff = root.dataset.staff === "true";
        const roomId = root.dataset.roomId;
        const abilities = window.LANG_HOME?.abilities || [];
        const canRead = staff && abilities.includes("availability.view");
        const canLock = staff && abilities.includes("availability.lock");
        const canCheckout = staff && abilities.includes("availability.checkout");
        const canHold = abilities.includes("availability.hold");
        const dateInput = one("[data-timeline-date]");
        const daysInput = one("[data-timeline-days]");
        const status = one("[data-timeline-status]");
        const grid = one("[data-timeline-grid]");
        const dialog = one("[data-timeline-dialog]");
        const body = one("[data-checkout-body]");
        const search = one("[data-timeline-search]");
        const bookingPanel = one("[data-timeline-booking]");
        const packageList = one("[data-timeline-packages]");
        const packageStatus = one("[data-timeline-package-status]");
        const bookingSummary = one("[data-timeline-booking-summary]");
        const bookingConfirm = one("[data-timeline-booking-confirm]");
        let choiceController = null;
        let bookingChoice = null;
        let packageOptions = [];
        let snapshot = null;
        let controller = null;
        let currentBooking = null;
        let currentCharge = null;
        let mutating = false;
        let returnFocus = null;
        let pendingBookingId = /^#booking-([a-f0-9]{32})$/.exec(location.hash)?.[1] || "";
        dateInput.value = localStamp(Date.now()).slice(0, 10);
        if (search) {
            const rounded = Math.ceil(Date.now() / 3600000) * 3600000;
            search.elements.from.value = localStamp(rounded);
            search.elements.to.value = localStamp(rounded + 3 * 3600000);
            // This script precedes the shared picker scripts in the page; initialize
            // after all deferred scripts have loaded.
            document.addEventListener("DOMContentLoaded", () => {
                for (const section of search.querySelectorAll("[data-timeline-datetime]")) {
                    const output = search.elements[section.dataset.timelineDatetime];
                    const date = section.querySelector('[name="date"]');
                    const time = section.querySelector("[data-datetime-time]");
                    date.value = output.value.slice(0, 10);
                    const datePicker = MoAdminDatePicker.create(section);
                    datePicker.refresh();
                    const timePicker = MoTimePicker.create(root, {
                        root: section.querySelector(".manual-time-picker"),
                        panel: section.querySelector(".manual-time-popover"),
                        fields: {
                            time,
                            arrivalHour: section.querySelector("[data-datetime-hour]"),
                            arrivalMinute: section.querySelector("[data-datetime-minute]"),
                        },
                    });
                    timePicker.set(output.value.slice(11, 16));
                    const sync = () => {
                        output.value = `${date.value}T${time.value}`;
                        output.dispatchEvent(new Event("input", { bubbles: true }));
                    };
                    date.addEventListener("change", sync);
                    time.addEventListener("change", sync);
                }
            }, { once: true });
        }

        async function request(url, method = "GET", data = null, signal = undefined) {
            const response = await fetch(url, {
                method, signal,
                headers: {
                    "Content-Type": "application/json",
                    ...(method !== "GET" ? { "X-CSRF-Token": window.LANG_HOME.csrf } : {}),
                },
                ...(data ? { body: JSON.stringify(data) } : {}),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || "Chưa kết nối được lịch phòng.");
            return result;
        }

        function showResults() {
            if (!search || !snapshot) return;
            const start = timestamp(search.elements.from.value + ":00+07:00");
            const end = timestamp(search.elements.to.value + ":00+07:00");
            const results = one("[data-timeline-results]");
            if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
                results.innerHTML = '<p class="text-sm text-red-800">Giờ ra phải sau giờ vào.</p>';
                return;
            }
            if (start < timestamp(snapshot.rangeStart) || end > timestamp(snapshot.rangeEnd)) {
                results.innerHTML = '<p class="text-sm text-[#736b61]">Bấm Tìm phòng trống để tải lịch cho khoảng giờ đã chọn.</p>';
                return;
            }
            const rooms = snapshot.rooms.filter((r) => r.active);
            const free = rooms.filter((r) => !r.intervals.some((i) => searchOverlaps(start, end, i)));
            results.innerHTML = `<p class="col-span-full text-sm font-semibold">${free.length} phòng trống toàn bộ khung giờ ${escape(dateTime(start))} – ${escape(dateTime(end))}</p>`
                + rooms.map((room) => {
                    const available = free.includes(room);
                    const windows = freeWindows(room, start, end);
                    return `<article class="availability-room-card">
                        <img class="availability-room-image" src="${escape(room.image)}" alt="" loading="lazy" decoding="async">
                        <div class="availability-room-shade" aria-hidden="true"></div>
                        <div class="availability-room-content">
                            <h3>${escape(room.name)}</h3>
                            <p class="availability-room-status" data-available="${available}">${available ? "Còn trống khung giờ này" : "Không trống toàn bộ khung giờ"}</p>
                            ${!available && windows.length ? `<p class="availability-room-windows">Khoảng trống trong thời gian chọn:<br>${windows.map(([a, b]) => `${escape(dateTime(a))} – ${escape(dateTime(b))}`).join("<br>")}</p>` : ""}
                            <a class="timeline-button availability-room-link" href="/rooms/${escape(room.slug)}" aria-label="Xem phòng ${escape(room.name)}">Xem phòng <span aria-hidden="true">↗</span></a>
                        </div>
                    </article>`;
                }).join("");
            results.querySelectorAll(".availability-room-image").forEach((image) => {
                image.addEventListener("error", () => image.remove(), { once: true });
            });
        }

        function paintBookingChoice() {
            grid.querySelectorAll("[data-slot-start]").forEach((cell) => {
                const selected = bookingChoice?.selected;
                const start = Number(cell.dataset.slotStart);
                const included = !!selected && cell.dataset.slotRoom === bookingChoice.room.id
                    && start < timestamp(selected.end) && start + 3600000 > timestamp(selected.start);
                cell.dataset.selected = String(included);
                cell.setAttribute("aria-pressed", String(included));
            });
        }

        function clearBookingChoice() {
            choiceController?.abort();
            choiceController = null;
            bookingChoice = null;
            packageOptions = [];
            bookingPanel.hidden = true;
            packageList.innerHTML = "";
            bookingSummary.hidden = true;
            bookingConfirm.hidden = true;
            bookingConfirm.disabled = false;
            paintBookingChoice();
        }

        function renderPackageOptions() {
            packageList.innerHTML = packageOptions.map((option) => `<button class="timeline-package" type="button" data-timeline-package="${escape(option.id)}" aria-pressed="${bookingChoice?.selected?.id === option.id}">
                <strong>${escape(option.name)}</strong>
                <span>${escape(dateTime(option.start))} – ${escape(dateTime(option.end))}</span>
                <span class="font-semibold">${cash(option.price)}</span>
            </button>`).join("");
        }

        async function selectBookingPackage(option) {
            bookingChoice.selected = option;
            renderPackageOptions();
            const selectedButton = [...packageList.querySelectorAll("[data-timeline-package]")]
                .find((button) => button.dataset.timelinePackage === option.id);
            selectedButton?.focus({ preventScroll: true });
            paintBookingChoice();
            bookingSummary.textContent = `${bookingChoice.room.name} · ${option.name} · ${dateTime(option.start)} – ${dateTime(option.end)} · ${cash(option.price)}`;
            bookingSummary.hidden = false;
            const choice = bookingChoice;
            const active = choiceController;
            if (timestamp(option.end) > timestamp(snapshot.rangeEnd)) {
                bookingConfirm.hidden = true;
                packageStatus.textContent = "Đang tải thêm ngày để hiển thị toàn bộ khung giờ đã chọn…";
                try {
                    const days = Math.ceil((timestamp(option.end) - timestamp(snapshot.rangeStart)) / 86400000);
                    const query = new URLSearchParams({ date: dateInput.value, days: String(days) });
                    const expanded = await request("/api/availability?" + query, "GET", null, active.signal);
                    if (choiceController !== active || bookingChoice !== choice || choice.selected !== option) return;
                    if (expanded.connected !== true) throw new Error("Chưa xác minh được lịch phòng.");
                    expanded.rooms.forEach((room) => {
                        const previous = snapshot.rooms.find((item) => item.id === room.id);
                        room.name = previous?.name || room.id;
                        room.slug = previous?.slug || room.id;
                        room.image = previous?.image;
                    });
                    snapshot = { ...snapshot, ...expanded };
                    let range = daysInput.querySelector("[data-selection-range]");
                    if (!range) {
                        range = document.createElement("option");
                        range.dataset.selectionRange = "true";
                        daysInput.append(range);
                    }
                    range.value = String(days);
                    range.textContent = days + " ngày";
                    daysInput.value = String(days);
                    renderGrid();
                    paintBookingChoice();
                } catch (error) {
                    if (active.signal.aborted || choiceController !== active || choice.selected !== option) return;
                    packageStatus.textContent = "Chưa hiển thị được toàn bộ khung giờ: " + error.message;
                    return;
                }
            }
            if (bookingChoice !== choice || choice.selected !== option) return;
            bookingConfirm.hidden = false;
            packageStatus.textContent = "Các ô màu nâu là thời gian lưu trú đã chọn. Lịch sẽ được kiểm tra lại khi xác nhận.";
        }

        async function chooseBookingHour(room, start) {
            clearBookingChoice();
            const active = new AbortController();
            choiceController = active;
            const local = localStamp(start);
            bookingChoice = { room, start, date: local.slice(0, 10), time: local.slice(11, 16), selected: null };
            bookingPanel.hidden = false;
            bookingPanel.scrollIntoView({ block: "nearest", behavior: "instant" });
            one("[data-timeline-package-title]").textContent = `${room.name} · ${dateTime(start)} · Chọn gói khả dụng`;
            packageStatus.textContent = "Đang kiểm tra các gói và toàn bộ lịch lưu trú…";
            try {
                const query = new URLSearchParams({ roomId: room.id, date: bookingChoice.date, time: bookingChoice.time });
                const result = await request("/api/availability/packages?" + query, "GET", null, active.signal);
                if (choiceController !== active) return;
                packageOptions = result.packages;
                renderPackageOptions();
                packageStatus.textContent = packageOptions.length
                    ? "Chọn một gói để xem khung giờ trên lịch. Giá hiển thị là giá trọn gói."
                    : "Không có gói khả dụng bắt đầu trong ô giờ này. Vui lòng chọn giờ hoặc phòng khác.";
            } catch (error) {
                if (active.signal.aborted || choiceController !== active) return;
                packageStatus.textContent = "Chưa xác minh được gói khả dụng: " + error.message;
            }
        }

        async function confirmBookingChoice() {
            if (!bookingChoice?.selected || bookingConfirm.disabled) return;
            const choice = bookingChoice;
            const active = choiceController;
            const selected = choice.selected;
            bookingConfirm.disabled = true;
            packageList.querySelectorAll("button").forEach((button) => { button.disabled = true; });
            packageStatus.textContent = "Đang kiểm tra lại lịch trước khi tiếp tục…";
            try {
                const query = new URLSearchParams({ roomId: choice.room.id, date: choice.date, time: choice.time });
                const result = await request("/api/availability/packages?" + query, "GET", null, active.signal);
                if (choiceController !== active || bookingChoice !== choice) return;
                const fresh = result.packages.find((option) => option.id === selected.id);
                if (!fresh || fresh.start !== selected.start || fresh.end !== selected.end || fresh.price !== selected.price) {
                    packageOptions = result.packages;
                    choice.selected = null;
                    renderPackageOptions();
                    paintBookingChoice();
                    bookingSummary.hidden = true;
                    bookingConfirm.hidden = true;
                    packageStatus.textContent = "Gói hoặc lịch phòng vừa thay đổi. Vui lòng chọn lại một gói khả dụng.";
                    return;
                }
                const params = new URLSearchParams({ room: choice.room.id, package: fresh.id, date: fresh.date, time: fresh.time, fromTimeline: "1" });
                location.href = "/bookings?" + params;
            } catch (error) {
                if (active.signal.aborted || choiceController !== active) return;
                packageStatus.textContent = "Chưa xác minh được lịch: " + error.message;
            } finally {
                if (choiceController === active) {
                    bookingConfirm.disabled = false;
                    packageList.querySelectorAll("button").forEach((button) => { button.disabled = false; });
                }
            }
        }

        function renderGrid() {
            const rooms = snapshot.rooms.filter((r) => !roomId || r.id === roomId);
            const start = timestamp(snapshot.rangeStart);
            const days = Math.round((timestamp(snapshot.rangeEnd) - start) / 86400000);
            const now = timestamp(snapshot.serverTime);
            let rows = "";
            for (let day = 0; day < days; day++) {
                const dayStart = start + day * 86400000;
                rooms.forEach((room) => {
                    const date = new Intl.DateTimeFormat("vi-VN", {
                        timeZone: "Asia/Ho_Chi_Minh", weekday: "short", day: "2-digit", month: "2-digit",
                    }).format(new Date(dayStart));
                    rows += `<tr><th class="timeline-row-heading" scope="row">${escape(date)}${staff ? `<span class="mt-1 block text-[#79543e]">${escape(room.name)}</span>` : ""}</th>`;
                    for (let slot = 0; slot < 24; slot++) {
                        const a = dayStart + slot * 3600000;
                        const state = cellState(room, a, a + 3600000, now);
                        const hour = `${String(slot).padStart(2, "0")}:00–${String(slot + 1).padStart(2, "0")}:00`;
                        const label = `${room.name} · ${date} ${hour} · ${labels[state]}`;
                        rows += `<td><button type="button" class="timeline-cell" data-state="${state}" data-slot-start="${a}" data-slot-room="${escape(room.id)}" aria-label="${escape(label)}" title="${escape(label)}">${state === "free" ? "Trống" : state === "booked" ? "Bận" : state === "blocked" ? "Khóa" : state === "cleaning" ? "Dọn phòng" : state === "held" ? "Giữ" : "—"}</button></td>`;
                    }
                    rows += "</tr>";
                });
            }
            grid.innerHTML = `<table class="timeline-table"><caption class="sr-only">Lịch phòng theo từng ô 1 giờ, giờ Việt Nam. Ô bận có thể chỉ bận một phần, bấm để xem chính xác.</caption>
                <thead><tr><th class="timeline-row-heading" scope="col">Ngày${staff ? " / Phòng" : ""}</th>${Array.from({ length: 24 }, (_, i) => `<th class="whitespace-nowrap" scope="col">${String(i).padStart(2, "0")}:00–${String(i + 1).padStart(2, "0")}:00</th>`).join("")}</tr></thead>
                <tbody>${rows || '<tr><td>Không tìm thấy phòng.</td></tr>'}</tbody></table>`;
        }

        function renderStays() {
            const section = one("[data-timeline-operations]");
            if (!canRead || (!canLock && !canCheckout)) return;
            section.hidden = false;
            const bookings = (snapshot.bookings || []).filter((b) => (!roomId || b.room_id === roomId)
                && b.status !== "CANCELLED" && (b.status === "CHECKED_IN" || Number(b.surcharge_amount) > 0));
            one("[data-timeline-stays]").innerHTML = bookings.map((b) => {
                const locked = b.emergency_lock_id && !b.ended_at;
                const unpaidSurcharge = Number(b.surcharge_amount) > 0 && ["DUE", "PENDING"].includes(b.surcharge_status);
                return `<article id="booking-${escape(b.id)}" class="rounded-xl border p-3 ${b.isOverdue || unpaidSurcharge ? 'border-red-400 bg-red-50' : 'border-[#e5dacb]'}">
                    <h4 class="font-semibold">${escape(b.room_name)} · ${escape(b.booking_code)}</h4>
                    <p class="mt-1 text-sm">Giờ trả dự kiến: ${escape(dateTime(utc(b.check_out)))}</p>
                    <p class="mt-1 text-sm ${b.isOverdue ? "text-red-800" : "text-[#736b61]"}">${b.status === "CHECKED_OUT" ? "Đã xác nhận trả phòng" : locked ? "Đang khóa khẩn cấp · khách chưa rời phòng" : b.isOverdue ? "Đã quá giờ · cần kiểm tra khách" : "Trong giờ lưu trú"}</p>
                    ${Number(b.surcharge_amount) > 0 ? `<p class="mt-2 rounded-lg p-2 text-sm font-bold ${unpaidSurcharge ? 'bg-red-100 text-red-900' : 'text-stone-700'}">Phụ thu: ${cash(b.surcharge_amount)} · ${b.surcharge_status === "PAID" ? "Đã thu" : b.surcharge_status === "CANCELLED" ? "Đã hủy" : "Chưa thu"}</p>` : ""}
                    <div class="mt-3 flex flex-wrap gap-2">
                        ${canLock && b.isOverdue && !locked ? `<button class="timeline-button" data-lock-booking="${escape(b.id)}" type="button">Khóa khẩn cấp</button>` : ""}
                        ${canCheckout && b.status === "CHECKED_IN" ? `<button class="timeline-button timeline-primary" data-checkout-booking="${escape(b.id)}" type="button">Xác nhận trả phòng</button>` : ""}
                        ${canCheckout && Number(b.surcharge_amount) > 0 && b.surcharge_status !== "CANCELLED" ? `<button class="timeline-button" data-charge-booking="${escape(b.id)}" type="button">Xem phụ thu / QR</button>` : ""}
                        ${window.LANG_HOME?.isSuperAdmin === true && unpaidSurcharge ? `<button class="timeline-button !border-red-400 !text-red-800 enabled:hover:!bg-red-100" data-cancel-surcharge="${escape(b.id)}" type="button">Hủy phụ thu</button>` : ""}
                    </div>
                </article>`;
            }).join("") || '<p class="text-sm text-[#736b61]">Chưa có khách đang lưu trú hoặc khoản phụ thu.</p>';
        }

        async function loadQuoteHolds(active) {
            if (!canHold) return;
            const section = one("[data-timeline-holds-section]");
            const list = one("[data-timeline-holds]");
            const message = one("[data-timeline-holds-status]");
            section.hidden = false;
            list.innerHTML = "";
            message.textContent = "Đang tải các khung giờ đang giữ…";
            try {
                const query = new URLSearchParams({ roomId });
                const result = await request("/api/admin/room-operations/holds?" + query, "GET", null, active.signal);
                if (controller !== active) return;
                message.textContent = result.holds.length ? "Bấm Mở khóa để trả khung giờ về trạng thái trống." : "Không có khung giờ đang giữ.";
                list.innerHTML = result.holds.map((hold) => {
                    const name = snapshot.rooms.find((room) => room.id === hold.room_id)?.name || hold.room_id;
                    return `<article class="rounded-xl border border-sky-200 bg-white p-3">
                        <h4 class="font-semibold">${escape(name)}</h4>
                        <p class="mt-1 text-sm">${escape(dateTime(utc(hold.starts_at)))} – ${escape(dateTime(utc(hold.ends_at)))}</p>
                        <p class="mt-1 text-sm">Tự mở lúc ${escape(dateTime(utc(hold.expires_at)))}</p>
                        <button type="button" class="timeline-button mt-3" data-release-hold="${escape(hold.id)}">Mở khóa</button>
                    </article>`;
                }).join("");
            } catch (error) {
                if (active.signal.aborted || controller !== active) return;
                message.textContent = "Chưa tải được danh sách mở khóa: " + error.message;
            }
        }

        async function load() {
            if (!dateInput.value) return;
            clearBookingChoice();
            controller?.abort();
            const active = new AbortController();
            controller = active;
            snapshot = null;
            grid.innerHTML = "";
            grid.setAttribute("aria-busy", "true");
            if (search) one("[data-timeline-results]").innerHTML = "";
            status.hidden = false;
            status.textContent = "Đang kết nối lịch phòng…";
            try {
                const query = new URLSearchParams({ date: dateInput.value, days: daysInput.value });
                if (canRead && Number(daysInput.value) > 14) {
                    daysInput.value = "7";
                    query.set("days", "7");
                }
                const result = await request((canRead ? "/api/admin/room-operations" : "/api/availability") + "?" + query, "GET", null, active.signal);
                if (controller !== active) return;
                if (result.connected !== true) throw new Error("Chưa xác minh được dữ liệu lịch phòng.");
                result.rooms.forEach((r) => {
                    const info = window.LANG_HOME?.rooms?.find((item) => item.id === r.id);
                    r.name = r.name || info?.name || r.id;
                    r.slug = r.slug || info?.slug || r.id;
                    r.image = info?.image || "/images/white-illustration.jpg";
                });
                snapshot = result;
                renderGrid();
                showResults();
                renderStays();
                await loadQuoteHolds(active);
                if (controller !== active) return;
                status.textContent = staff ? "" : `Cập nhật ${dateTime(result.serverTime)} · Cuộn ngang để xem các giờ.`;
                status.hidden = staff;
                if (staff && pendingBookingId) {
                    const id = pendingBookingId;
                    pendingBookingId = "";
                    const card = document.getElementById(`booking-${id}`);
                    const button = card?.querySelector("[data-checkout-booking], [data-charge-booking]");
                    if (card && root.contains(card) && button) {
                        card.classList.add("ring-2", "ring-[#79543e]", "ring-offset-2", "scroll-mt-24");
                        button.focus({ preventScroll: true });
                        card.scrollIntoView({ block: "center", behavior: "instant" });
                    } else {
                        status.hidden = false;
                        status.textContent = "Đơn này không còn trong danh sách khách đang ở hoặc bạn chưa có quyền trả phòng. Vui lòng kiểm tra lại trạng thái đơn.";
                    }
                }
            } catch (error) {
                if (error.name === "AbortError") return;
                status.hidden = false;
                status.textContent = "Chưa tải được lịch thực tế: " + error.message;
                one("[data-timeline-stays]").innerHTML = "";
            } finally {
                if (controller === active) grid.setAttribute("aria-busy", "false");
            }
        }

        function openDialog(bookingId) {
            currentBooking = bookingId;
            currentCharge = null;
            returnFocus = document.activeElement;
            if (!dialog.open) dialog.showModal();
        }

        function closeDialog() {
            if (mutating) return;
            dialog.close();
            if (returnFocus?.isConnected) returnFocus.focus();
            else one("[data-timeline-refresh]").focus();
        }

        function renderCharge(charge, warning = "") {
            currentCharge = charge;
            const paid = charge.paymentStatus === "PAID";
            const cancelled = charge.paymentStatus === "CANCELLED";
            body.innerHTML = `<p class="text-sm">Giờ trả thực tế: ${escape(dateTime(utc(charge.actualCheckoutAt)))}</p>
                <p class="mt-2 text-sm">Trễ ${Math.ceil(charge.lateSeconds / 60)} phút · Miễn 15 phút đầu · ${charge.billableHours} giờ tính phí</p>
                <p class="mt-4 text-2xl font-semibold">${cash(charge.amount)}</p>
                <p class="mt-2 text-sm">${cancelled ? "Khoản phụ thu đã hủy." : charge.amount === 0 ? "Không phát sinh phụ thu." : paid ? "Đã nhận tiền phụ thu." : "Chưa nhận tiền phụ thu. Khoản này tách riêng tiền đặt phòng."}</p>
                ${warning ? `<p class="mt-3 text-sm text-red-800" role="status">${escape(warning)}</p>` : ""}
                <div class="mt-4 flex flex-wrap gap-2">
                    ${charge.amount > 0 && !paid && !cancelled && charge.checkoutUrl ? `<a class="timeline-button timeline-primary" href="${escape(charge.checkoutUrl)}" target="_blank" rel="noopener noreferrer">Mở mã QR thanh toán ↗</a><button type="button" class="timeline-button" data-copy-charge-link>Sao chép link gửi khách</button>` : ""}
                    ${charge.amount > 0 && !paid && !cancelled && !charge.checkoutUrl ? '<button class="timeline-button timeline-primary" type="button" data-checkout-pay>Tạo lại QR</button>' : ""}
                    ${charge.amount > 0 ? '<button class="timeline-button" type="button" data-charge-refresh>Kiểm tra đã thu</button>' : ""}
                </div><p class="mt-3 text-sm text-[#736b61]" data-charge-status role="status"></p>`;
        }

        root.addEventListener("click", async (event) => {
            const packageButton = event.target.closest("[data-timeline-package]");
            if (packageButton && bookingChoice && !bookingConfirm.disabled) {
                const option = packageOptions.find((item) => item.id === packageButton.dataset.timelinePackage);
                if (option) await selectBookingPackage(option);
                return;
            }
            if (event.target.closest("[data-timeline-booking-confirm]")) {
                await confirmBookingChoice();
                return;
            }
            if (event.target.closest("[data-timeline-booking-cancel]")) {
                clearBookingChoice();
                return;
            }
            const slot = event.target.closest("[data-slot-start]");
            if (slot && snapshot) {
                clearBookingChoice();
                const a = Number(slot.dataset.slotStart);
                const room = snapshot.rooms.find((r) => r.id === slot.dataset.slotRoom);
                const intervals = room.intervals.filter((i) => overlaps(a, a + 3600000, i));
                one("[data-timeline-selection]").textContent = `${room.name} · ${dateTime(a)}: `
                    + (intervals.length ? intervals.map((i) => `${i.status === "BLOCKED" ? "Đang khóa" : i.status === "CLEANING" ? "Dọn phòng" : i.status === "HELD" ? "Đang giữ" : "Đã đặt"} ${dateTime(i.start)} – ${dateTime(i.end)}`).join("; ")
                        : labels[cellState(room, a, a + 3600000, timestamp(snapshot.serverTime))]);
                if (cellState(room, a, a + 3600000, timestamp(snapshot.serverTime)) === "free") {
                    await chooseBookingHour(room, a);
                }
                return;
            }
            const cancelSurcharge = event.target.closest("[data-cancel-surcharge]");
            if (cancelSurcharge && !mutating) {
                const confirmed = await LangUI["confirm"]({
                    title: "Hủy khoản phụ thu?",
                    message: "Khoản phụ thu chưa thu sẽ được hủy và ngừng thu tiền. Trạng thái trả phòng giữ nguyên.",
                    confirmLabel: "Hủy phụ thu", cancelLabel: "Quay lại", tone: "danger",
                });
                if (!confirmed) return;
                mutating = true;
                cancelSurcharge.disabled = true;
                try {
                    await request("/api/admin/room-operations/cancel-surcharge", "POST", { bookingId: cancelSurcharge.dataset.cancelSurcharge });
                    LangUI.toast({ type: "success", title: "Đã hủy phụ thu", message: "Khoản này không còn phải thu." });
                    await load();
                } catch (error) {
                    LangUI.toast({ type: "error", title: "Chưa hủy được phụ thu", message: error.message });
                } finally {
                    mutating = false;
                    cancelSurcharge.disabled = false;
                }
                return;
            }
            const release = event.target.closest("[data-release-hold]");
            if (release && !mutating) {
                release.disabled = true;
                mutating = true;
                try {
                    await request("/api/admin/room-operations/release", "POST", { holdId: release.dataset.releaseHold });
                    await load();
                } catch (error) {
                    one("[data-timeline-holds-status]").textContent = "Chưa mở khóa được: " + error.message;
                } finally {
                    release.disabled = false;
                    mutating = false;
                }
                return;
            }
            const lock = event.target.closest("[data-lock-booking]");
            const checkout = event.target.closest("[data-checkout-booking]");
            const chargeButton = event.target.closest("[data-charge-booking]");
            const pay = event.target.closest("[data-checkout-pay]");
            const confirm = event.target.closest("[data-checkout-confirm]");
            const refreshCharge = event.target.closest("[data-charge-refresh]");
            const copy = event.target.closest("[data-copy-charge-link]");
            if (copy && currentCharge?.checkoutUrl) {
                try {
                    await navigator.clipboard.writeText(currentCharge.checkoutUrl);
                    one("[data-charge-status]").textContent = "Đã sao chép link QR thanh toán.";
                } catch {
                    one("[data-charge-status]").textContent = "Mở QR và sao chép địa chỉ trang để gửi khách.";
                }
                return;
            }
            if (checkout && snapshot) {
                openDialog(checkout.dataset.checkoutBooking);
                const b = snapshot.bookings.find((item) => item.id === currentBooking);
                const late = Math.max(0, timestamp(snapshot.serverTime) - utc(b.check_out));
                const hours = Math.ceil(Math.max(0, late - 900000) / 3600000);
                body.innerHTML = `<p>${escape(b.room_name)} · ${escape(b.booking_code)}</p>
                    <p class="mt-3 text-sm">Khách đã rời phòng? Xác nhận sẽ mở khóa khẩn cấp và ghi giờ trả thực tế.</p>
                    <p class="mt-3 font-semibold">Phụ thu tạm tính: ${cash(hours * 50000)}</p>
                    <p class="mt-2 text-sm text-[#736b61]">Miễn 15 phút đầu; phần còn lại làm tròn giờ × 50.000đ. Máy chủ chốt tiền tại lúc bấm xác nhận.</p>
                    <label class="mt-4 flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-[#d2bda7] p-3 text-sm">
                        <input class="size-5 accent-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" type="checkbox" data-checkout-collect>
                        Xác nhận thu phụ thu và tạo mã chuyển khoản
                    </label>
                    <p class="mt-2 text-sm text-[#736b61]">Không tích: chỉ trả phòng, không thu phụ thu.</p>
                    <button class="timeline-button timeline-primary mt-4" type="button" data-checkout-confirm>Khách đã rời phòng · Xác nhận</button>`;
                return;
            }
            const target = lock || chargeButton || pay || confirm || refreshCharge;
            if (!target || target.disabled || mutating) return;
            if (lock) {
                const yes = await LangUI["confirm"]({
                    title: "Khóa khẩn cấp phòng?",
                    message: "Xác nhận khách chưa rời phòng dù đã tới giờ trả. Phòng sẽ bị chặn đặt mới cho đến khi nhân viên xác nhận trả phòng. Các đơn kế tiếp vẫn được giữ để xử lý.",
                    confirmLabel: "Khóa phòng", cancelLabel: "Quay lại", tone: "danger",
                });
                if (!yes) return;
            }
            if (chargeButton) openDialog(chargeButton.dataset.chargeBooking);
            mutating = true;
            target.disabled = true;
            try {
                if (lock) {
                    const result = await request("/api/admin/room-operations/lock", "POST", { bookingId: lock.dataset.lockBooking });
                    const affected = result.affectedBookings || [];
                    LangUI.toast({
                        type: affected.length ? "error" : "success",
                        title: "Đã khóa khẩn cấp",
                        message: affected.length ? `Cần xử lý các đơn kế tiếp: ${affected.map((b) => b.booking_code).join(", ")}` : "Phòng ngừng nhận đặt mới cho đến khi xác nhận trả phòng.",
                    });
                    await load();
                } else if (chargeButton || refreshCharge) {
                    const result = await request("/api/admin/room-operations/charge?bookingId=" + encodeURIComponent(currentBooking));
                    renderCharge(result.charge);
                } else {
                    const payload = { bookingId: currentBooking };
                    if (confirm) payload.collectSurcharge = one("[data-checkout-collect]")?.checked === true;
                    const result = await request("/api/admin/room-operations/" + (confirm ? "checkout" : "payment"), "POST", payload);
                    renderCharge(result.charge, result.paymentError || "");
                    await load();
                }
            } catch (error) {
                if (dialog.open) {
                    const p = document.createElement("p");
                    p.className = "mt-3 text-sm text-red-800";
                    p.setAttribute("role", "alert");
                    p.textContent = error.message;
                    body.append(p);
                } else LangUI.toast({ type: "error", title: "Chưa thực hiện được", message: error.message });
            } finally {
                target.disabled = false;
                mutating = false;
            }
        });

        one("[data-checkout-close]").addEventListener("click", closeDialog);
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            closeDialog();
        });
        one("[data-timeline-refresh]").addEventListener("click", load);
        dateInput.addEventListener("change", load);
        daysInput.addEventListener("change", load);
        function scrollToNow() {
            const hour = Number(localStamp(Date.now()).slice(11, 13));
            const scroll = one("[data-timeline-scroll]");
            const cells = grid.querySelectorAll("tbody tr:first-child [data-slot-start]");
            const cell = cells[Math.max(0, hour - 1)];
            if (cell) {
                const heading = grid.querySelector("tbody .timeline-row-heading");
                scroll.scrollLeft = Math.max(0,
                    scroll.scrollLeft + cell.getBoundingClientRect().left
                    - scroll.getBoundingClientRect().left - (heading?.getBoundingClientRect().width || 0));
            }
        }
        one("[data-timeline-now]").addEventListener("click", () => {
            const today = localStamp(Date.now()).slice(0, 10);
            if (dateInput.value !== today) {
                dateInput.value = today;
                load().then(scrollToNow);
            } else scrollToNow();
        });
        if (search) {
            search.addEventListener("submit", (event) => {
                event.preventDefault();
                const from = timestamp(search.elements.from.value + ":00+07:00");
                const to = timestamp(search.elements.to.value + ":00+07:00");
                if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from || to - from > 7 * 86400000) {
                    status.textContent = "Chọn giờ ra sau giờ vào, tối đa 7 ngày.";
                    return;
                }
                dateInput.value = search.elements.from.value.slice(0, 10);
                const start = timestamp(dateInput.value + "T00:00:00+07:00");
                const days = Math.ceil((to - start) / 86400000);
                if (![1, 3, 7].includes(days)) {
                    let option = daysInput.querySelector("[data-search-range]");
                    if (!option) {
                        option = document.createElement("option");
                        option.dataset.searchRange = "true";
                        daysInput.append(option);
                    }
                    option.value = String(days);
                    option.textContent = days + " ngày";
                }
                daysInput.value = String(days);
                load();
            });
            search.addEventListener("input", showResults);
        }
        setInterval(() => {
            if (!document.hidden && !dialog.open && !mutating && !root.contains(document.activeElement)) load();
        }, 30000);
        root.addEventListener("mo:inventory-changed", load);
        document.addEventListener("mo:inventory-changed", load);
        load();
    });
})();
