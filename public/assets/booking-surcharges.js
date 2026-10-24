(() => {
    "use strict";

    if (!window.LANG_HOME?.abilities?.includes("availability.checkout")) return;
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[char]);
    const cash = (amount) => Number(amount).toLocaleString("vi-VN") + "đ";
    const dialog = document.createElement("dialog");
    dialog.className = "timeline-dialog";
    dialog.setAttribute("aria-label", "Phụ thu của đơn phòng");
    dialog.innerHTML = `
        <div class="flex items-start justify-between gap-3 border-b border-[#e5dacb] p-4">
            <h2 class="font-sans text-lg font-semibold" data-fee-title>Phụ thu</h2>
            <button class="timeline-button" type="button" data-fee-close>Đóng</button>
        </div>
        <div class="grid gap-4 p-4">
            <div class="grid gap-2" data-fee-list></div>
            <p class="text-sm font-semibold" data-fee-total></p>
            <form class="grid gap-3" data-fee-form>
                <label class="grid gap-1 text-sm">Tên phụ thu
                    <input class="timeline-input" name="name" maxlength="150" required placeholder="Ví dụ: nước uống, giặt khăn">
                </label>
                <label class="grid gap-1 text-sm">Giá (đ)
                    <input class="timeline-input" name="amount" type="number" min="1" max="100000000" step="1" required inputmode="numeric">
                </label>
                <button class="timeline-button timeline-primary" type="submit">Lưu phụ thu</button>
            </form>
            <p class="text-sm text-stone-600" data-fee-message role="status"></p>
            <p class="text-sm text-stone-600">Các khoản đã lưu sẽ được cộng với phụ thu giờ được chọn khi trả phòng để tạo một mã QR chung. Giờ phát sinh đã thanh toán khi đặt phòng không bị thu lại.</p>
        </div>`;
    document.body.append(dialog);
    const one = (selector) => dialog.querySelector(selector);
    const form = one("[data-fee-form]");
    let bookingId = "";
    let requestId = "";
    let previousPayload = "";
    let busy = false;
    let trigger = null;
    let version = 0;

    async function request(method, data = null) {
        const query = method === "GET" ? "?" + new URLSearchParams({ bookingId }) : "";
        const response = await fetch("/api/admin/room-operations/surcharges" + query, {
            method,
            headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
            ...(data ? { body: JSON.stringify(data) } : {}),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Chưa lưu được phụ thu.");
        return result;
    }
    function render(result) {
        one("[data-fee-list]").innerHTML = result.items.map((item) => `
            <div class="flex items-start justify-between gap-3 rounded-lg border border-[#e5dacb] p-3 text-sm">
                <span>${escape(item.name)}</span><strong class="shrink-0">${cash(item.amount)}</strong>
            </div>`).join("") || '<p class="text-sm text-stone-600">Chưa có khoản phụ thu.</p>';
        one("[data-fee-total]").textContent = "Tổng phụ thu đã lưu: " + cash(result.items.reduce((sum, item) => sum + Number(item.amount), 0));
        form.hidden = !result.editable;
        if (!result.editable) one("[data-fee-message]").textContent = "Đơn đã chốt hoặc ngừng nhận phụ thu. Xem QR trong Trả phòng & phụ thu.";
    }
    function close() {
        if (busy) return;
        version++;
        dialog.close();
        trigger?.focus({ preventScroll: true });
    }
    one("[data-fee-close]").addEventListener("click", close);
    dialog.addEventListener("cancel", (event) => {
        event.preventDefault();
        close();
    });
    document.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-booking-surcharge]");
        if (!button || busy) return;
        trigger = button;
        bookingId = button.dataset.bookingSurcharge;
        requestId = "";
        previousPayload = "";
        const active = ++version;
        form.reset();
        form.hidden = true;
        one("[data-fee-title]").textContent = "Phụ thu · " + (button.dataset.bookingSurchargeLabel || "Đơn phòng");
        one("[data-fee-list]").innerHTML = "";
        one("[data-fee-total]").textContent = "";
        one("[data-fee-message]").textContent = "Đang tải phụ thu…";
        if (!dialog.open) dialog.showModal();
        try {
            const result = await request("GET");
            if (version !== active) return;
            one("[data-fee-message]").textContent = "";
            render(result);
            if (result.editable) form.elements.name.focus();
        } catch (error) {
            if (version === active) one("[data-fee-message]").textContent = error.message;
        }
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        const payload = { bookingId, name: form.elements.name.value.trim(), amount: Number(form.elements.amount.value) };
        const signature = JSON.stringify(payload);
        if (signature !== previousPayload || !requestId) {
            requestId = crypto.randomUUID().replaceAll("-", "");
            previousPayload = signature;
        }
        busy = true;
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            const result = await request("POST", { ...payload, requestId });
            one("[data-fee-message]").textContent = "Đã lưu phụ thu. Sẽ thu chung khi trả phòng.";
            render(result);
            form.reset();
            requestId = "";
            previousPayload = "";
            document.dispatchEvent(new Event("mo:inventory-changed"));
        } catch (error) {
            one("[data-fee-message]").textContent = error.message;
        } finally {
            busy = false;
            submit.disabled = false;
        }
    });
})();
