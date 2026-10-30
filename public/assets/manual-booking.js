(() => {
    "use strict";

    const dialog = document.querySelector("[data-manual-booking-dialog]");
    if (!dialog) return;
    const form = dialog.querySelector("form");
    const fields = form.elements;
    const allowAnyDate = window.LANG_HOME?.manualDateOverride === true;
    const summary = dialog.querySelector("[data-manual-summary]");
    const errorBox = dialog.querySelector("[data-manual-error]");
    const discard = dialog.querySelector("[data-manual-discard]");
    const submit = dialog.querySelector("[data-manual-submit]");
    const trigger = document.querySelector("[data-create-booking]");
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[char]);
    const cash = (value) => new Intl.NumberFormat("vi-VN").format(value) + "đ";
    const dateTime = (date) => new Intl.DateTimeFormat("vi-VN", {
        timeZone: "Asia/Ho_Chi_Minh", dateStyle: "short", timeStyle: "short",
    }).format(date);
    let rooms = [];
    let dirty = false;
    let saving = false;
    let available = false;
    let revision = 0;
    let requestId = "";
    const timePicker = MoTimePicker.create(dialog, { minuteStep: () => fields.timingMode.value === 'CUSTOM' ? 1 : 30 });
    const datePicker = MoAdminDatePicker.create(dialog);

    async function request(url, body) {
        const response = await fetch(url, body ? {
            method: "POST",
            headers: { ...(body instanceof FormData ? {} : { "Content-Type": "application/json" }), "X-CSRF-Token": LANG_HOME.csrf },
            body: body instanceof FormData ? body : JSON.stringify(body),
        } : {});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Không thể kết nối máy chủ.");
        return data;
    }

    function selectedRoom() {
        return rooms.find((room) => room.id === fields.roomId.value);
    }

    function selectedPackage() {
        const room = selectedRoom();
        const slot = room?.packages.find((item) => item.id === fields.packageId.value);
        return slot ? MoBookingSlots.priceForArrival(room, slot, fields.date.value) : null;
    }

    function setArrivalTime(value) {
        timePicker.set(value);
    }

    const isCustom = () => fields.timingMode.value === "CUSTOM";
    const endTime = () => String(fields.endHour.value).padStart(2, "0") + ":" + String(fields.endMinute.value).padStart(2, "0");

    function updateMode() {
        const custom = isCustom();
        dialog.querySelector("[data-manual-package-field]").hidden = custom;
        dialog.querySelector("[data-manual-now-field]").hidden = !custom;
        dialog.querySelector("[data-manual-custom-end]").hidden = !custom;
        fields.packageId.required = !custom;
        fields.packageId.disabled = custom;
        fields.startNow.disabled = !custom;
        for (const name of ["endDate", "endHour", "endMinute"]) {
            fields[name].disabled = !custom;
            fields[name].required = custom;
        }
        dialog.querySelector("#manual-date-trigger").disabled = custom && fields.startNow.checked;
    }

    function customWindow() {
        if (!fields.endDate.value || fields.endHour.value === "" || fields.endMinute.value === "") return null;
        const now = new Date();
        const start = fields.startNow.checked ? now : new Date(fields.date.value + "T" + fields.time.value + ":00+07:00");
        const end = new Date(fields.endDate.value + "T" + endTime() + ":00+07:00");
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return null;
        if (!allowAnyDate && !fields.startNow.checked && start.getTime() < now.getTime() - 60000) return null;
        return { start, end, fixed: true };
    }

    function updatePackages() {
        const room = selectedRoom();
        const previous = fields.packageId.value;
        const packages = MoBookingSlots.configuredSlots(room).map((slot) => MoBookingSlots.priceForArrival(room, slot, fields.date.value));
        fields.packageId.innerHTML = packages.length
            ? packages.map((item) => `<option value="${escape(item.id)}">${escape(item.name)} · ${cash(item.price)}</option>`).join("")
            : '<option value="">Phòng chưa có gói đang bật</option>';
        if (packages.some((slot) => slot.id === previous)) fields.packageId.value = previous;
        fields.numberOfGuests.max = room?.maxGuests || 50;
        fields.numberOfGuests.value = Math.min(Number(fields.numberOfGuests.value) || 1, room?.maxGuests || 1);
        fields.agreedPrice.value = isCustom() ? "" : (selectedPackage()?.price ?? "");
    }

    async function updateAvailability() {
        const current = ++revision;
        available = false;
        submit.disabled = true;
        const room = selectedRoom();
        const slot = selectedPackage();
        updateMode();
        const custom = isCustom();
        if (custom && fields.startNow.checked) {
            const now = new Date();
            fields.date.value = new Date(now.getTime() + 7 * 3600000).toISOString().slice(0, 10);
            datePicker.refresh();
            setArrivalTime(new Intl.DateTimeFormat("en-GB", {
                timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", hourCycle: "h23",
            }).format(now));
        }
        timePicker.setDisabled(custom ? fields.startNow.checked : slot?.mode === "FIXED_TIME");
        if (!custom && slot?.mode === "FIXED_TIME") setArrivalTime(slot.checkInTime);
        const window = custom ? customWindow() : MoBookingSlots.slotWindow(slot, fields.date.value, fields.time.value, allowAnyDate ? new Date(-8640000000000000) : new Date());
        summary.dataset.state = "loading";
        if (!room || !window) {
            summary.textContent = custom ? "Chọn giờ nhận hợp lệ và giờ trả sau giờ nhận phòng." : "Chọn phòng, gói lưu trú và thời gian nhận phòng.";
            return;
        }
        const details = `<strong>${escape(room.name)} · ${escape(custom ? "Thời gian tùy chọn" : slot.name)}</strong><br>Nhận: ${dateTime(window.start)} → Trả: ${dateTime(window.end)}<br>Tổng tiền: <strong data-manual-total>${(fields.agreedPrice.value === "" ? "Chưa nhập giá" : cash(fields.agreedPrice.value))}</strong>`;
        summary.innerHTML = details + "<br>Đang kiểm tra lịch phòng…";
        try {
            const startDate = new Date(window.start.getTime() + 7 * 3600000).toISOString().slice(0, 10);
            const rangeStart = new Date(startDate + "T00:00:00+07:00");
            const days = Math.max(1, Math.ceil((window.end.getTime() + 3600000 - rangeStart.getTime()) / 86400000));
            let data = null;
            for (let offset = 0; offset < days; offset += 62) {
                const date = new Date(rangeStart.getTime() + offset * 86400000 + 7 * 3600000).toISOString().slice(0, 10);
                const part = await request("/api/availability?date=" + encodeURIComponent(date) + "&days=" + Math.min(62, days - offset));
                if (current !== revision || !dialog.open) return;
                if (!part.connected) throw new Error("Chưa kết nối được lịch phòng.");
                if (!data) data = part;
                else data.rooms.forEach((item) => {
                    const next = part.rooms.find((r) => r.id === item.id);
                    if (!next?.active) item.active = false;
                    item.intervals.push(...(next?.intervals || []));
                });
            }
            if (current !== revision || !dialog.open) return;
            const inventory = data.rooms.find((item) => item.id === room.id);
            if (!data.connected || !inventory?.active) throw new Error("Phòng hiện không nhận đặt hoặc chưa kết nối được lịch.");
            const state = MoBookingSlots.slotState(window, inventory.intervals, allowAnyDate ? new Date(-8640000000000000) : new Date());
            available = state === "available";
            summary.dataset.state = state;
            summary.innerHTML = details + "<br>" + (available
                ? "Còn trống. Đơn sẽ giữ lịch ngay khi tạo."
                : state === "past" ? "Giờ nhận phòng đã qua. Chọn thời gian khác."
                    : "Khung giờ này đã có đơn hoặc phòng tạm khóa. Chọn giờ khác.");
            submit.disabled = !available || saving;
        } catch (error) {
            if (current !== revision || !dialog.open) return;
            summary.dataset.state = "error";
            summary.textContent = error.message;
        }
    }

    function closeDialog() {
        revision++;
        dialog.close();
        document.body.classList.remove("dialog-open");
        trigger.focus();
    }

    function requestClose() {
        if (saving) return;
        if (!dirty) return closeDialog();
        discard.hidden = false;
        dialog.querySelector("[data-manual-continue]").focus();
    }

    trigger.addEventListener("click", async () => {
        form.reset();
        dirty = false;
        available = false;
        errorBox.hidden = true;
        discard.hidden = true;
        requestId = [...crypto.getRandomValues(new Uint8Array(16))]
            .map((value) => value.toString(16).padStart(2, "0")).join("");
        const now = new Date();
        fields.timingMode.value = "PACKAGE";
        fields.startNow.checked = false;
        fields.endDate.value = "";
        fields.endHour.value = "";
        fields.endMinute.value = "";
        fields.date.value = new Intl.DateTimeFormat("en-CA", {
            timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit",
        }).format(now);
        fields.date.min = allowAnyDate ? "" : fields.date.value;
        const lastDate = MoAdminDatePicker.parse(fields.date.value);
        lastDate.setUTCDate(lastDate.getUTCDate() + 366);
        fields.date.max = allowAnyDate ? "" : lastDate.toISOString().slice(0, 10);
        datePicker.refresh();
        setArrivalTime(new Intl.DateTimeFormat("en-GB", {
            timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", hourCycle: "h23",
        }).format(now));
        fields.roomId.innerHTML = '<option value="">Đang tải phòng…</option>';
        summary.textContent = "Đang tải phòng và các gói lưu trú…";
        summary.dataset.state = "loading";
        submit.disabled = true;
        dialog.showModal();
        document.body.classList.add("dialog-open");
        try {
            const data = await request("/api/admin/booking-rooms");
            if (!dialog.open) return;
            rooms = data.rooms.filter((room) => room.status === "ACTIVE");
            fields.roomId.innerHTML = rooms.length
                ? rooms.map((room) => `<option value="${escape(room.id)}">${escape(room.name)}</option>`).join("")
                : '<option value="">Chưa có phòng đang hoạt động</option>';
            updatePackages();
            await updateAvailability();
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
        }
    });

    dialog.querySelectorAll("[data-manual-close]").forEach((button) => button.addEventListener("click", requestClose));
    dialog.addEventListener("cancel", (event) => {
        event.preventDefault();
        requestClose();
    });
    dialog.querySelector("[data-manual-continue]").onclick = () => {
        discard.hidden = true;
        fields.guestName.focus();
    };
    dialog.querySelector("[data-manual-discard-confirm]").onclick = closeDialog;
    form.addEventListener("input", () => {
        dirty = true;
        errorBox.hidden = true;
        const total = summary.querySelector("[data-manual-total]");
        if (total) total.textContent = fields.agreedPrice.value === "" ? "Chưa nhập giá" : cash(fields.agreedPrice.value);
    });
    form.addEventListener("change", (event) => {
        if (["roomId", "date"].includes(event.target.name)) updatePackages();
        if (event.target.name === "timingMode" && isCustom()) {
            fields.startNow.checked = false;
            fields.date.value = "";
            datePicker.refresh();
            setArrivalTime("00:00");
            fields.time.value = "";
            fields.arrivalHour.value = "";
            fields.arrivalMinute.value = "";
            dialog.querySelector("[data-time-hour-label]").textContent = "Chọn giờ";
            dialog.querySelector("[data-time-minute-label]").textContent = "Chọn phút";
            fields.endDate.value = "";
            fields.endHour.value = "";
            fields.endMinute.value = "";
        }
        if (["packageId", "timingMode", "date"].includes(event.target.name)) {
            fields.agreedPrice.value = isCustom() ? "" : (selectedPackage()?.price ?? "");
        }
        if (["arrivalHour", "arrivalMinute"].includes(event.target.name)) {
            setArrivalTime(`${fields.arrivalHour.value}:${fields.arrivalMinute.value}`);
            updateAvailability();
        }
        if (["roomId", "packageId", "date", "time", "timingMode", "startNow", "endDate", "endHour", "endMinute"].includes(event.target.name)) updateAvailability();
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving || !available || !form.reportValidity()) return;
        const payload = Object.fromEntries(new FormData(form));
        payload.numberOfGuests = Number(payload.numberOfGuests);
        payload.time = fields.time.value;
        if (isCustom()) payload.endTime = endTime();
        delete payload.endHour;
        delete payload.endMinute;
        delete payload.arrivalHour;
        delete payload.arrivalMinute;
        payload.requestId = requestId;
        payload.guestCheckout = fields.guestCheckout?.checked === true;
        saving = true;
        timePicker.close();
        datePicker.close();
        const controls = [...dialog.querySelectorAll("input,select,textarea,button")];
        const disabled = controls.map((control) => control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        form.setAttribute("aria-busy", "true");
        try {
            const upload = new FormData();
            Object.entries(payload).forEach(([key, value]) => {
                if (!(value instanceof File)) upload.append(key, String(value));
            });
            ["identity_front", "identity_back"].forEach((name) => {
                const file = payload[name];
                if (file instanceof File && file.size > 0) upload.append(name, file);
            });
            const data = await request("/api/admin/bookings", upload);
            dirty = false;
            if (data.guestUrl) {
                window.location.assign(data.guestUrl);
                return;
            }
            closeDialog();
            window.dispatchEvent(new CustomEvent("mo:booking-created"));
            LangUI.toast({ type: "success", title: "Đã tạo đơn " + data.bookingCode, message: "Đơn đã xác nhận và giữ lịch phòng." });
        } catch (error) {
            errorBox.textContent = error.message + " Bạn có thể kiểm tra danh sách hoặc thử lại; yêu cầu này không tạo đơn trùng.";
            errorBox.hidden = false;
            errorBox.scrollIntoView({ block: "nearest" });
        } finally {
            saving = false;
            controls.forEach((control, index) => { control.disabled = disabled[index]; });
            form.removeAttribute("aria-busy");
            if (dialog.open) await updateAvailability();
        }
    });
    window.addEventListener("beforeunload", (event) => {
        if (dialog.open && dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
})();
