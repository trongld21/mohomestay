(() => {
    "use strict";
    const root = document.querySelector("[data-mock-payments]");
    if (!root) return;
    const form = root.querySelector("form");
    const fields = form.elements;
    const out = root.querySelector("[data-mock-result]");
    let rooms = [];
    let booking = null;
    let busy = false;
    let requestId = "";
    const escape = (value) => String(value).replace(/[&<>"']/g, (char) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[char]);
    async function request(url, body) {
        const response = await fetch(url, body ? {
            method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
            body: JSON.stringify(body),
        } : {});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Yêu cầu thất bại.");
        return result;
    }
    function render() {
        out.innerHTML = `<div class="rounded-xl border border-[#e6dccf] bg-white p-5"><strong>${escape(booking.bookingCode)} · ĐƠN THỬ</strong><p>orderCode: ${escape(booking.orderCode)}</p><p>Số tiền: ${booking.amount.toLocaleString("vi-VN")}đ</p><p class="mt-3"><a class="text-link" href="${escape(booking.paymentUrl)}" target="_blank" rel="noopener">2. Mở màn thanh toán ở tab mới →</a></p><button type="button" class="button mt-4 rounded-lg" data-mock-callback>3. Gửi callback thanh toán thành công</button><p class="mt-3 text-sm" data-mock-status></p></div>`;
    }
    function packages() {
        const room = rooms.find((item) => item.id === fields.roomId.value);
        fields.packageId.innerHTML = (room?.packages || []).filter((item) => item.enabled)
            .map((item) => `<option value="${escape(item.id)}">${escape(item.name)}</option>`).join("");
        timing();
    }
    function timing() {
        const room = rooms.find((item) => item.id === fields.roomId.value);
        const slot = room?.packages.find((item) => item.id === fields.packageId.value);
        fields.time.disabled = slot?.mode === "FIXED_TIME";
        if (slot?.mode === "FIXED_TIME") fields.time.value = slot.checkInTime;
    }
    form.onchange = (event) => {
        requestId = "";
        if (event.target.name === "roomId") packages();
        if (event.target.name === "packageId") timing();
    };
    form.onsubmit = async (event) => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        form.querySelector("button").disabled = true;
        requestId ||= crypto.randomUUID().replaceAll("-", "");
        try {
            booking = await request("/api/dev/payments/create", {
                ...Object.fromEntries(new FormData(form)), time: fields.time.value,
                requestId, guestName: "Khách thử callback", guestPhone: "0900000000",
                numberOfGuests: 1, bookingSource: "PHONE",
            });
            render();
        } catch (error) { out.textContent = error.message; }
        finally { busy = false; form.querySelector("button").disabled = false; }
    };
    out.onclick = async (event) => {
        const button = event.target.closest("[data-mock-callback]");
        if (!button || busy || !booking) return;
        busy = true;
        button.disabled = true;
        try {
            const result = await request("/api/dev/payments/callback", { orderCode: booking.orderCode, amount: booking.amount });
            out.querySelector("[data-mock-status]").textContent = `Callback: ${result.status} / ${result.paymentStatus}. Tab thanh toán sẽ tự cập nhật; đơn hợp lệ chuyển sang trang thành công.`;
        } catch (error) { out.querySelector("[data-mock-status]").textContent = error.message; }
        finally { busy = false; button.disabled = false; }
    };
    (async () => {
        try {
            const data = await request("/api/admin/rooms");
            rooms = data.rooms.filter((room) => room.status === "ACTIVE");
            fields.roomId.innerHTML = rooms.map((room) => `<option value="${escape(room.id)}">${escape(room.name)}</option>`).join("");
            const tomorrow = new Date(LANG_HOME.today + "T12:00:00Z");
            tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
            fields.date.value = tomorrow.toISOString().slice(0, 10);
            fields.date.min = LANG_HOME.today;
            packages();
        } catch (error) { out.textContent = error.message; }
    })();
})();
