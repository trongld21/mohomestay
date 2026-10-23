(() => {
    const rooms = Array.isArray(window.LANG_HOME?.rooms) ? window.LANG_HOME.rooms : [];
    const activeRooms = rooms.filter((room) => room.status === "ACTIVE");
    const one = (selector, root = document) => root.querySelector(selector),
        all = (selector, root = document) => [...root.querySelectorAll(selector)];
    const esc = (value) =>
        String(value ?? "").replace(
            /[&<>"']/g,
            (char) =>
                ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char],
        );
    const cash = (value) => new Intl.NumberFormat("vi-VN").format(Number(value || 0)) + "đ";
    const params = () => Object.fromEntries(new URLSearchParams(location.search));
    const bookableSlots = (room) => window.MoBookingSlots?.configuredSlots(room) || [];
    async function request(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: { ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }), ...(options.headers || {}) },
        });
        let data = {};
        try {
            data = await response.json();
        } catch {}
        if (!response.ok) throw new Error(data.error || "Không thể kết nối máy chủ.");
        return data;
    }
    function packageLabel(pkg) {
        if (pkg.mode === "FIXED_TIME") return `${pkg.checkInTime} – ${pkg.checkOutTime}`;
        return pkg.durationMinutes % 60 === 0
            ? `${pkg.durationMinutes / 60} giờ`
            : `${pkg.durationMinutes} phút`;
    }
    function packageWindow(pkg, date, time) {
        if (!pkg || !date) return null;
        let start, end;
        if (pkg.mode === "FIXED_TIME") {
            if (!pkg.checkInTime || !pkg.checkOutTime) return null;
            start = new Date(`${date}T${pkg.checkInTime}:00+07:00`);
            end = new Date(`${date}T${pkg.checkOutTime}:00+07:00`);
            if (end <= start) end = new Date(end.getTime() + 86400000);
        } else {
            if (!time) return null;
            start = new Date(`${date}T${time}:00+07:00`);
            end = new Date(start.getTime() + Number(pkg.durationMinutes) * 60000);
        }
        return { start, end };
    }
    const overlaps = (window, interval) =>
        window.start < new Date(interval.end) && window.end > new Date(interval.start);

    function initHomeSearch() {
        const form = one(".search-bar");
        if (!form) return;
        const roomSelect = form.elements.room,
            packageSelect = form.elements.package,
            submit = one('[type="submit"]', form);
        const refresh = () => {
            const room = activeRooms.find((item) => item.id === roomSelect.value) || activeRooms[0],
                slots = bookableSlots(room);
            packageSelect.innerHTML = slots.length
                ? slots
                      .map(
                          (pkg) =>
                              `<option value="${esc(pkg.id)}">${esc(pkg.name)} · ${esc(packageLabel(pkg))} · ${cash(pkg.price)}</option>`,
                      )
                      .join("")
                : '<option value="">Chưa có gói đặt online</option>';
            submit.disabled = !slots.length;
        };
        roomSelect.innerHTML =
            '<option value="">Chọn phòng</option>' +
            activeRooms
                .map((room) => `<option value="${esc(room.id)}">${esc(room.name)}</option>`)
                .join("");
        roomSelect.onchange = refresh;
        refresh();
        form.onsubmit = (event) => {
            event.preventDefault();
            if (submit.disabled) return;
            location.href = `/calendar?room=${encodeURIComponent(roomSelect.value)}&package=${encodeURIComponent(packageSelect.value)}&date=${encodeURIComponent(form.elements.date.value)}`;
        };
    }

    function initBooking() {
        const root = one("#booking-app");
        if (!root || !window.MoBookingSlots) return;
        const form = one("[data-booking-form]", root),
            summary = one("[data-summary]", root),
            message = one("[data-booking-message]", root),
            terms = one("[data-terms]", root),
            slotGrid = one("[data-slot-grid]", root),
            durationField = one("[data-duration-time]", root),
            query = params();
        const roomSelect = form.elements.roomId,
            submit = one(".booking-submit", form),
            slotTools = window.MoBookingSlots;
        let availability = null,
            selectedId = "",
            durationTime = query.time || "";
        const testPayment = one("[data-test-payment]", form);
        let testRequest = { payload: "", id: "" };
        if (activeRooms.some((room) => room.id === query.room)) {
            roomSelect.value = query.room;
            // Notify the enhanced dropdown after applying the room from the URL.
            roomSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(query.date || "")) form.date.value = query.date;
        if (!form.date.value) form.date.value = LANG_HOME.today;
        form.time.value = durationTime;

        const room = () =>
            activeRooms.find((item) => item.id === roomSelect.value) || activeRooms[0];
        const slots = () => slotTools.configuredSlots(room());
        const selectedSlot = () => slots().find((slot) => slot.id === selectedId);
        const availabilityForRoom = () =>
            availability?.rooms?.find((item) => item.id === room()?.id);
        const clockLabel = (value) => value.toLocaleTimeString("vi-VN", {
            timeZone: "Asia/Ho_Chi_Minh",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
        });
        const prettyDate = (value) =>
            new Date(`${value}T12:00:00+07:00`).toLocaleDateString("vi-VN", {
                weekday: "long",
                day: "2-digit",
                month: "2-digit",
                year: "numeric",
            });

        function syncGuests() {
            const current = room(),
                previous = Number(form.numberOfGuests.value || 2);
            form.numberOfGuests.innerHTML = Array.from(
                { length: current?.maxGuests || 1 },
                (_, index) => `<option value="${index + 1}">${index + 1} khách</option>`,
            ).join("");
            form.numberOfGuests.value = Math.min(previous, current?.maxGuests || 1);
        }
        function clearSelection() {
            selectedId = "";
            form.packageId.value = "";
            durationField.hidden = true;
            form.time.required = false;
            submit.disabled = true;
            submit.textContent = "Chọn một gói để tiếp tục";
        }
        function renderSummary() {
            const current = room(),
                slot = selectedSlot(),
                window = slotTools.slotWindow(slot, form.date.value, form.time.value);
            if (!current) {
                summary.innerHTML = '<div class="empty-state">Chưa có phòng đang mở.</div>';
                return;
            }
            const timeLabel = window
                ? `${clockLabel(window.start)} – ${clockLabel(window.end)}`
                : "Chưa chọn";
            summary.innerHTML = `<div class="summary-image"><img src="${esc(current.image)}" alt="Phòng ${esc(current.name)}"></div><span class="summary-kicker">TÓM TẮT CUỘC HẸN</span><h2>${esc(current.name)}</h2><p class="muted">${esc(current.subtitle)}</p><div class="room-tag-chips">${current.tags.map((tag) => `<span>${esc(tag)}</span>`).join("")}</div><div class="summary-row"><span>Ngày ghé</span><strong>${prettyDate(form.date.value)}</strong></div><div class="summary-row"><span>Khung giờ</span><strong>${timeLabel}</strong></div><div class="summary-row"><span>Gói lưu trú</span><strong>${esc(slot?.name || "—")}</strong></div><div class="summary-row total"><span>Tổng tiền</span><strong>${slot ? cash(slot.price) : "—"}</strong></div>${slot ? '<p class="summary-assurance"><span aria-hidden="true">✓</span> Khung giờ được giữ 15 phút khi tạo đơn.</p>' : '<p class="summary-prompt">Chọn một gói còn trống để xem đầy đủ chi tiết.</p>'}`;
        }
        function chooseSlot(slot) {
            const previous = selectedSlot();
            if (previous?.mode === "DURATION" && form.time.value) durationTime = form.time.value;
            selectedId = slot.id;
            form.packageId.value = slot.id;
            durationField.hidden = slot.mode !== "DURATION";
            form.time.required = slot.mode === "DURATION";
            form.time.value = slot.mode === "FIXED_TIME" ? slot.checkInTime : durationTime;
            form.time.dispatchEvent(new Event("mo:time-sync"));
            renderSlots();
            renderSummary();
        }
        function renderSlots() {
            const list = slots(),
                info = availabilityForRoom();
            slotGrid.setAttribute("aria-busy", availability ? "false" : "true");
            if (!availability) {
                slotGrid.innerHTML =
                    '<div class="slot-skeleton"></div><div class="slot-skeleton"></div>';
                return;
            }
            if (!list.length) {
                slotGrid.innerHTML =
                    '<div class="slot-empty"><strong>Phòng chưa có gói đặt online.</strong><span>Nhắn Mơ để được tư vấn lịch phù hợp nhé.</span></div>';
                return;
            }
            slotGrid.innerHTML = list
                .map((slot, index) => {
                    const isSelected = slot.id === selectedId,
                        time =
                            slot.mode === "FIXED_TIME"
                                ? slot.checkInTime
                                : isSelected
                                  ? form.time.value
                                  : "",
                        window = slotTools.slotWindow(slot, form.date.value, time),
                        baseState = window
                            ? slotTools.slotState(window, info?.intervals || [])
                            : "available",
                        state = !info?.active ? "closed" : baseState,
                        selected =
                            (state === "available" || slot.mode === "DURATION") && isSelected;
                    const label =
                        state === "booked"
                            ? "Đã được đặt"
                            : state === "past"
                              ? "Đã qua giờ"
                              : state === "closed"
                                ? "Phòng chưa mở"
                                : slot.mode === "DURATION" && !selected
                                  ? "Chọn gói"
                                  : "Còn trống";
                    const timeLabel =
                        slot.mode === "FIXED_TIME"
                            ? `<b>${esc(window ? clockLabel(window.start) : slot.checkInTime)}</b><i aria-hidden="true"></i><b>${esc(window ? clockLabel(window.end) : slot.checkOutTime)}</b>`
                            : `<b>${esc(packageLabel(slot))}</b><i aria-hidden="true"></i><b>${selected && form.time.value ? "Từ " + esc(form.time.value) : "Tự chọn giờ"}</b>`;
                    return `<button class="booking-slot-card is-${state}${selected ? " is-selected" : ""}" type="button" data-slot-id="${esc(slot.id)}" ${state !== "available" && (slot.mode !== "DURATION" || state === "closed") ? "disabled" : ""} aria-pressed="${selected}" style="--slot-index:${index}"><span class="slot-card-top"><strong>${esc(slot.name)}</strong><span class="slot-state">${label}</span></span><span class="slot-time">${timeLabel}</span><span class="slot-price"><small>Trọn gói</small><strong>${cash(slot.price)}</strong></span></button>`;
                })
                .join("");
            if (
                selectedId &&
                !one(`[data-slot-id="${CSS.escape(selectedId)}"]:not(:disabled)`, slotGrid)
            )
                clearSelection();
            const slot = selectedSlot(),
                window = slotTools.slotWindow(slot, form.date.value, form.time.value),
                state = window ? slotTools.slotState(window, info?.intervals || []) : "needs-time",
                canSubmit = (availability.onlineBooking || testPayment?.checked) && info?.active && state === "available";
            submit.disabled = !canSubmit;
            submit.textContent = !availability.onlineBooking && !testPayment?.checked
                ? "Đặt online chưa mở"
                : !slot
                  ? "Chọn một gói để tiếp tục"
                  : state === "needs-time"
                    ? "Chọn giờ nhận phòng"
                    : state === "booked"
                      ? "Khung giờ đã được đặt"
                      : state === "past"
                        ? "Khung giờ đã qua"
                        : "Tiếp tục thanh toán →";
        }
        async function loadAvailability() {
            availability = null;
            message.innerHTML = "";
            clearSelection();
            renderSlots();
            renderSummary();
            try {
                availability = await request(
                    "/api/availability?date=" + encodeURIComponent(form.date.value),
                );
                terms.hidden = availability.onlineBooking && !availability.terms;
                terms.textContent = !availability.onlineBooking
                    ? "Đặt phòng trực tuyến chưa mở. Bạn vẫn có thể xem các gói và khung giờ; hãy nhắn Mơ để được hỗ trợ đặt chỗ."
                    : availability.terms
                      ? "Điều kiện đặt phòng: " + availability.terms
                      : "";
                renderSlots();
                const preferred = slots().find((slot) => slot.id === query.package),
                    button =
                        preferred &&
                        one(
                            `[data-slot-id="${CSS.escape(preferred.id)}"]:not(:disabled)`,
                            slotGrid,
                        );
                if (button) chooseSlot(preferred);
                else renderSummary();
            } catch (error) {
                slotGrid.innerHTML = `<div class="slot-empty is-error"><strong>Chưa tải được lịch phòng.</strong><span>${esc(error.message)}</span></div>`;
                message.innerHTML = `<p class="notice error">${esc(error.message)}</p>`;
            }
        }
        slotGrid.onclick = (event) => {
            const button = event.target.closest("[data-slot-id]:not(:disabled)");
            if (!button) return;
            const slot = slots().find((item) => item.id === button.dataset.slotId);
            if (slot) chooseSlot(slot);
        };
        roomSelect.onchange = () => {
            syncGuests();
            loadAvailability();
        };
        testPayment?.addEventListener("change", () => {
            renderSlots();
            renderSummary();
        });
        form.date.onchange = loadAvailability;
        form.time.onchange = () => {
            durationTime = form.time.value;
            renderSlots();
            renderSummary();
        };
        form.onsubmit = async (event) => {
            event.preventDefault();
            message.innerHTML = "";
            if (!selectedSlot()) {
                message.innerHTML = '<p class="notice error">Vui lòng chọn một gói còn trống.</p>';
                one("[data-slot-section]", form)?.focus();
                return;
            }
            if (!form.reportValidity()) return;
            const upload = new FormData(form);
            const data = Object.fromEntries([...upload].filter(([, value]) => !(value instanceof File)));
            data.numberOfGuests = Number(data.numberOfGuests);
            data.acceptTerms = !!data.acceptTerms;
            const testing = !!testPayment?.checked;
            if (testing) {
                const payload = JSON.stringify(data);
                if (payload !== testRequest.payload) {
                    testRequest = { payload, id: crypto.randomUUID().replaceAll("-", "") };
                }
                data.requestId = testRequest.id;
            }
            upload.set('numberOfGuests', String(data.numberOfGuests));
            upload.set('acceptTerms', String(data.acceptTerms));
            submit.disabled = true;
            submit.textContent = "Đang lưu giấy tờ và giữ khung giờ…";
            try {
                const result = await request(testing ? "/api/dev/payments/create" : "/api/bookings", {
                    method: "POST",
                    ...(testing ? { headers: { "X-CSRF-Token": LANG_HOME.csrf } } : {}),
                    body: testing ? JSON.stringify(data) : upload,
                });
                if (testing) {
                    location.href = result.paymentUrl;
                    return;
                }
                try {
                    sessionStorage.setItem(
                        "mo-payment-" + result.orderCode,
                        JSON.stringify({ code: result.bookingCode, token: result.token }),
                    );
                } catch {}
                LangUI.toast({
                    type: "success",
                    title: "Đã giữ khung giờ",
                    message: "Đang chuyển đến trang thanh toán.",
                });
                location.href =
                    "/payment?code=" + encodeURIComponent(result.bookingCode) + "#" + result.token;
            } catch (error) {
                message.innerHTML = `<p class="notice error">${esc(error.message)}</p>`;
                LangUI.toast({
                    type: "error",
                    title: "Chưa thể đặt phòng",
                    message: error.message,
                });
                await loadAvailability();
            } finally {
                if (selectedSlot()) {
                    submit.disabled = false;
                    submit.textContent = "Tiếp tục thanh toán →";
                }
            }
        };
        syncGuests();
        loadAvailability();
    }
    initHomeSearch();
    initBooking();
})();
