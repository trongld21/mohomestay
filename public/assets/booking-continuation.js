(() => {
    "use strict";
    const abilities = window.LANG_HOME?.abilities || [];
    if (!abilities.includes("bookings.create")) return;
    const canCollect = abilities.includes("bookings.payment");
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[character]);
    const cash = (value) => Number(value).toLocaleString("vi-VN") + "đ";
    const dateTime = (value) => new Date(value).toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" });
    const dialog = document.createElement("dialog");
    dialog.className = "timeline-dialog";
    dialog.setAttribute("aria-label", "Ở tiếp và thêm gói cho cùng khách");
    dialog.innerHTML = `
        <div class="flex items-start justify-between gap-3 border-b border-[#e5dacb] p-4">
            <h2 class="font-sans text-lg font-semibold">Ở tiếp / thêm gói</h2>
            <button class="timeline-button" type="button" data-continuation-close>Đóng</button>
        </div>
        <form class="grid gap-4 p-4" data-continuation-form>
            <p class="text-sm font-semibold" data-continuation-title></p>
            <p class="text-sm text-stone-600">Nối đúng giờ trả gói trước cho cùng khách và cùng phòng. Không dọn giữa hai gói; vẫn chừa 1 giờ dọn sau gói cuối.</p>
            <label class="grid gap-1 text-sm">Gói ở tiếp<select class="timeline-input" name="packageId" required></select></label>
            <p class="text-sm font-semibold" data-continuation-summary></p>
            <label class="grid gap-1 text-sm">Thanh toán gói mới<select class="timeline-input" name="paymentStatus">
                <option value="UNPAID">Chưa thanh toán · thu riêng gói ở tiếp</option>
                ${canCollect ? '<option value="PAID">Đã thu tiền gói mới</option>' : ""}
            </select></label>
            <label class="grid gap-1 text-sm">Phương thức thu tiền<select class="timeline-input" name="paymentMethod"><option value="bank_transfer">Chuyển khoản</option><option value="cash">Tiền mặt</option></select></label>
            <p class="text-sm text-stone-600" data-continuation-message role="status"></p>
            <button class="timeline-button timeline-primary" type="submit" disabled>Tạo gói ở tiếp</button>
        </form>`;
    document.body.append(dialog);
    const one = (selector) => dialog.querySelector(selector);
    const form = one("[data-continuation-form]");
    const submit = form.querySelector('[type="submit"]');
    let bookingId = "";
    let packages = [];
    let trigger = null;
    let busy = false;
    let version = 0;
    let requestId = "";
    let lastPayload = "";

    async function request(method, payload = null) {
        const response = await fetch(`/api/admin/bookings/${encodeURIComponent(bookingId)}/continuation`, {
            method,
            headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
            ...(payload ? { body: JSON.stringify(payload) } : {}),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Chưa nối được gói lưu trú.");
        return result;
    }
    function renderSummary() {
        const selected = packages.find((item) => item.id === form.elements.packageId.value);
        one("[data-continuation-summary]").textContent = selected
            ? `${dateTime(selected.start)} → ${dateTime(selected.end)} · ${cash(selected.price)}` : "";
        submit.disabled = busy || !selected;
    }
    function close() {
        if (busy) return;
        version++;
        dialog.close();
        if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    }
    one("[data-continuation-close]").addEventListener("click", close);
    dialog.addEventListener("cancel", (event) => { event.preventDefault(); close(); });
    form.elements.packageId.addEventListener("change", renderSummary);
    document.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-booking-continuation]");
        if (!button || busy) return;
        trigger = button;
        bookingId = button.dataset.bookingContinuation;
        packages = [];
        form.reset();
        form.elements.packageId.innerHTML = "";
        one("[data-continuation-summary]").textContent = "";
        one("[data-continuation-title]").textContent = "";
        one("[data-continuation-message]").textContent = "Đang kiểm tra gói khả dụng…";
        submit.disabled = true;
        requestId = "";
        lastPayload = "";
        const active = ++version;
        if (!dialog.open) dialog.showModal();
        try {
            const result = await request("GET");
            if (active !== version) return;
            packages = result.packages;
            one("[data-continuation-title]").textContent = `${result.roomName} · Ở tiếp đơn ${result.bookingCode}`;
            form.elements.packageId.innerHTML = packages.map((item) => `<option value="${escape(item.id)}">${escape(item.name)} · ${cash(item.price)}</option>`).join("");
            one("[data-continuation-message]").textContent = packages.length
                ? "Máy chủ sẽ kiểm tra lại lịch khi tạo gói mới." : "Không có gói phù hợp bắt đầu đúng giờ trả hoặc khung giờ tiếp theo đã bận.";
            renderSummary();
        } catch (error) {
            if (active === version) one("[data-continuation-message]").textContent = error.message;
        }
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity() || !packages.some((item) => item.id === form.elements.packageId.value)) return;
        const payload = {
            packageId: form.elements.packageId.value,
            paymentStatus: form.elements.paymentStatus.value,
            paymentMethod: form.elements.paymentMethod.value,
        };
        const signature = JSON.stringify(payload);
        if (!requestId || lastPayload !== signature) {
            requestId = crypto.randomUUID().replaceAll("-", "");
            lastPayload = signature;
        }
        busy = true;
        const controls = [...form.querySelectorAll("input,select,button")];
        controls.forEach((control) => { control.disabled = true; });
        try {
            const result = await request("POST", { ...payload, requestId });
            busy = false;
            close();
            window.dispatchEvent(new CustomEvent("mo:booking-created"));
            document.dispatchEvent(new Event("mo:inventory-changed"));
            LangUI.toast({ type: "success", title: "Đã nối gói " + result.bookingCode, message: "Khách ở liền mạch; dọn phòng sau gói cuối." });
        } catch (error) {
            one("[data-continuation-message]").textContent = error.message;
        } finally {
            busy = false;
            controls.forEach((control) => { control.disabled = false; });
            renderSummary();
        }
    });
})();
