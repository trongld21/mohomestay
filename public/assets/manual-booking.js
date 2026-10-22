(() => {
    "use strict";

    const dialog = document.querySelector("[data-manual-booking-dialog]");
    if (!dialog) return;
    const form = dialog.querySelector("form");
    const fields = form.elements;
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
    const timePicker = MoTimePicker.create(dialog);
    const datePicker = MoAdminDatePicker.create(dialog);

    async function request(url, body) {
        const response = await fetch(url, body ? {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
            body: JSON.stringify(body),
        } : {});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Không thể kết nối máy chủ.");
        return data;
    }

    function selectedRoom() {
        return rooms.find((room) => room.id === fields.roomId.value);
    }

    function selectedPackage() {
        return selectedRoom()?.packages.find((item) => item.id === fields.packageId.value);
    }

    function setArrivalTime(value) {
        timePicker.set(value);
    }

    function updatePackages() {
        const room = selectedRoom();
        const packages = MoBookingSlots.configuredSlots(room);
        fields.packageId.innerHTML = packages.length
            ? packages.map((item) => `<option value="${escape(item.id)}">${escape(item.name)} · ${cash(item.price)}</option>`).join("")
            : '<option value="">Phòng chưa có gói đang bật</option>';
        fields.numberOfGuests.max = room?.maxGuests || 50;
        fields.numberOfGuests.value = Math.min(Number(fields.numberOfGuests.value) || 1, room?.maxGuests || 1);
    }

    async function updateAvailability() {
        const current = ++revision;
        available = false;
        submit.disabled = true;
        const room = selectedRoom();
        const slot = selectedPackage();
        timePicker.setDisabled(slot?.mode === "FIXED_TIME");
        if (slot?.mode === "FIXED_TIME") setArrivalTime(slot.checkInTime);
        const window = MoBookingSlots.slotWindow(slot, fields.date.value, fields.time.value);
        summary.dataset.state = "loading";
        if (!room || !window) {
            summary.textContent = "Chọn phòng, gói lưu trú và thời gian nhận phòng.";
            return;
        }
        const details = `<strong>${escape(room.name)} · ${escape(slot.name)}</strong><br>Nhận: ${dateTime(window.start)} → Trả: ${dateTime(window.end)}<br>Tổng tiền: <strong>${cash(slot.price)}</strong>`;
        summary.innerHTML = details + "<br>Đang kiểm tra lịch phòng…";
        try {
            const data = await request("/api/availability?date=" + encodeURIComponent(fields.date.value));
            if (current !== revision || !dialog.open) return;
            const inventory = data.rooms.find((item) => item.id === room.id);
            if (!data.connected || !inventory?.active) throw new Error("Phòng hiện không nhận đặt hoặc chưa kết nối được lịch.");
            const state = MoBookingSlots.slotState(window, inventory.intervals);
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
        fields.date.value = new Intl.DateTimeFormat("en-CA", {
            timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit",
        }).format(now);
        fields.date.min = fields.date.value;
        const lastDate = MoAdminDatePicker.parse(fields.date.value);
        lastDate.setUTCDate(lastDate.getUTCDate() + 366);
        fields.date.max = lastDate.toISOString().slice(0, 10);
        datePicker.refresh();
        setArrivalTime(new Intl.DateTimeFormat("en-GB", {
            timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", hourCycle: "h23",
        }).format(new Date(now.getTime() + 3600000)));
        fields.roomId.innerHTML = '<option value="">Đang tải phòng…</option>';
        summary.textContent = "Đang tải phòng và các gói lưu trú…";
        summary.dataset.state = "loading";
        submit.disabled = true;
        dialog.showModal();
        document.body.classList.add("dialog-open");
        try {
            const data = await request("/api/admin/rooms");
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
    });
    form.addEventListener("change", (event) => {
        if (event.target.name === "roomId") updatePackages();
        if (["arrivalHour", "arrivalMinute"].includes(event.target.name)) {
            setArrivalTime(`${fields.arrivalHour.value}:${fields.arrivalMinute.value}`);
            updateAvailability();
        }
        if (["roomId", "packageId", "date", "time"].includes(event.target.name)) updateAvailability();
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving || !available || !form.reportValidity()) return;
        const payload = Object.fromEntries(new FormData(form));
        payload.numberOfGuests = Number(payload.numberOfGuests);
        payload.time = fields.time.value;
        delete payload.arrivalHour;
        delete payload.arrivalMinute;
        payload.requestId = requestId;
        saving = true;
        timePicker.close();
        datePicker.close();
        const controls = [...dialog.querySelectorAll("input,select,textarea,button")];
        const disabled = controls.map((control) => control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        form.setAttribute("aria-busy", "true");
        try {
            const data = await request("/api/admin/bookings", payload);
            dirty = false;
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
