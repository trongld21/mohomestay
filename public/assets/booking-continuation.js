(() => {
    "use strict";
    const abilities = window.LANG_HOME?.abilities || [];
    if (!abilities.includes("bookings.create")) return;
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[character]);
    const cash = (value) => Number(value).toLocaleString("vi-VN") + "đ";
    const dateTime = (value) => new Date(value).toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" });
    const button = "min-h-11 rounded-lg border border-amber-200 px-3 py-2 text-sm font-semibold text-amber-950 enabled:cursor-pointer enabled:hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800 disabled:opacity-50";
    const primary = "min-h-11 rounded-lg bg-amber-800 px-4 py-2 text-sm font-semibold text-white enabled:cursor-pointer enabled:hover:bg-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800 disabled:opacity-50";
    const input = "box-border min-h-11 w-full min-w-0 rounded-lg border border-stone-300 bg-white px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800";
    const dialog = document.createElement("dialog");
    dialog.className = "m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-lg overflow-auto rounded-2xl border border-stone-200 bg-white p-5 text-stone-800 shadow-xl backdrop:bg-black/40";
    dialog.setAttribute("aria-labelledby", "continuation-dialog-title");
    dialog.innerHTML = `
        <header class="flex items-center justify-between gap-3">
            <h2 id="continuation-dialog-title" class="text-lg font-semibold">Ở tiếp / thêm gói</h2>
            <button class="${button}" type="button" data-continuation-close>Đóng</button>
        </header>
        <p class="mt-3 text-sm font-semibold" data-continuation-title></p>
        <form class="mt-4 grid gap-4" data-continuation-form>
            <div data-continuation-fields hidden>
                <div class="grid gap-4">
                    <label class="grid gap-1 text-sm">Gói ở tiếp<select class="${input}" name="packageId" required></select></label>
                    <p class="text-sm font-semibold" data-continuation-summary></p>
                    <fieldset data-continuation-identity hidden>
                        <legend class="text-sm font-semibold">Bổ sung CCCD cho đơn gốc</legend>
                        <p class="mb-2 text-sm text-stone-600" data-continuation-identity-note></p>
                        ${[["front", "Mặt trước"], ["back", "Mặt sau"]].map(([side, label]) => `
                            <div data-continuation-side="${side}" hidden>
                                <label class="mt-2 grid gap-1 text-sm">${label}
                                    <input type="file" name="identity_${side}" accept="image/jpeg,image/png,image/webp"
                                        class="${input} truncate enabled:file:cursor-pointer enabled:hover:file:bg-amber-100">
                                </label>
                            </div>`).join("")}
                    </fieldset>
                    <label class="grid gap-1 text-sm">Thanh toán gói mới<select class="${input}" name="payment">
                        <option value="QR">Tạo link QR chuyển khoản cho khách</option>
                        <option value="UNPAID">Thu riêng · chưa thanh toán</option>
                        ${abilities.includes("bookings.payment") ? '<option value="PAID">Đã thu đủ tiền gói mới</option>' : ""}
                    </select></label>
                    <div data-continuation-method hidden>
                        <label class="grid gap-1 text-sm">Phương thức thu tiền<select class="${input}" name="paymentMethod">
                            <option value="bank_transfer">Chuyển khoản</option>
                            <option value="cash">Tiền mặt</option>
                        </select></label>
                    </div>
                    <button class="${primary}" type="submit" disabled>Tạo gói và lấy link QR</button>
                </div>
            </div>
        </form>
        <div class="mt-4" data-continuation-link hidden>
            <label class="grid gap-1 text-sm">Link thanh toán gửi riêng cho khách
                <input class="${input}" data-continuation-url readonly>
            </label>
            <button class="${button} mt-2" type="button" data-continuation-copy>Sao chép link QR</button>
        </div>
        <button class="${button} mt-4" type="button" data-continuation-retry hidden>Lấy link QR</button>
        <p class="mt-3 text-sm text-stone-700" data-continuation-message role="status"></p>`;
    document.body.append(dialog);
    const one = (selector) => dialog.querySelector(selector);
    const form = one("[data-continuation-form]");
    const fields = one("[data-continuation-fields]");
    const submit = form.querySelector('[type="submit"]');
    const retry = one("[data-continuation-retry]");
    const message = one("[data-continuation-message]");
    const link = one("[data-continuation-link]");
    const url = one("[data-continuation-url]");
    let bookingId = "";
    let packages = [];
    let trigger = null;
    let busy = false;
    let version = 0;
    let requestId = "";
    let lastSelection = "";
    let pendingCode = "";

    async function request(method, payload) {
        const multipart = payload instanceof FormData;
        const response = await fetch(`/api/admin/bookings/${encodeURIComponent(bookingId)}/continuation`, {
            method,
            cache: "no-store",
            headers: { ...(multipart ? {} : { "Content-Type": "application/json" }), "X-CSRF-Token": LANG_HOME.csrf },
            ...(payload ? { body: multipart ? payload : JSON.stringify(payload) } : {}),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Chưa nối được gói lưu trú.");
        return result;
    }

    function renderSummary() {
        const selected = packages.find((item) => item.id === form.elements.packageId.value);
        const online = form.elements.payment.value === "QR";
        one("[data-continuation-summary]").textContent = selected
            ? `${dateTime(selected.start)} → ${dateTime(selected.end)} · ${cash(selected.price)}` : "";
        one("[data-continuation-method]").hidden = online;
        submit.textContent = online ? "Tạo gói và lấy link QR" : "Tạo gói ở tiếp";
        submit.disabled = busy || !selected;
    }

    function close() {
        if (busy) return;
        version++;
        form.reset();
        url.value = "";
        dialog.close();
        if (trigger?.isConnected) trigger.focus({ preventScroll: true });
        else document.querySelector("[data-booking-search]")?.focus({ preventScroll: true });
    }

    function setMissingIdentity(sides, code) {
        one("[data-continuation-identity]").hidden = !sides.length;
        one("[data-continuation-identity-note]").textContent = `Đơn ${code} còn thiếu ảnh. JPG, PNG hoặc WebP, tối đa 3 MiB mỗi mặt.`;
        for (const side of ["front", "back"]) {
            const missing = sides.includes(side);
            const wrapper = one(`[data-continuation-side="${side}"]`);
            wrapper.hidden = !missing;
            const file = wrapper.querySelector("input");
            file.required = missing;
            file.disabled = !missing;
        }
    }

    function showResult(result) {
        const { bookingCode, guestUrl, paymentError, paymentMessage, paymentStatus } = result;
        pendingCode = bookingCode;
        fields.hidden = true;
        form.reset();
        one("[data-continuation-title]").textContent = `Gói ở tiếp ${bookingCode}`;
        link.hidden = !guestUrl;
        retry.hidden = !paymentError;
        if (guestUrl) url.value = new URL(guestUrl, window.location.origin).href;
        message.textContent = paymentError || paymentMessage || (guestUrl
            ? "Gửi link cho khách. Gói ở tiếp được xác nhận khi nhận đủ tiền."
            : paymentStatus === "PAID" ? "Đã ghi nhận thanh toán gói ở tiếp." : "Đã lưu gói ở tiếp.");
        window.dispatchEvent(new CustomEvent("mo:booking-created", { detail: { bookingCode } }));
        document.dispatchEvent(new Event("mo:inventory-changed"));
        (guestUrl ? one("[data-continuation-copy]") : paymentError ? retry : one("[data-continuation-close]")).focus();
    }

    one("[data-continuation-close]").addEventListener("click", close);
    dialog.addEventListener("cancel", (event) => {
        event.preventDefault();
        close();
    });
    form.elements.packageId.addEventListener("change", renderSummary);
    form.elements.payment.addEventListener("change", renderSummary);
    one("[data-continuation-copy]").addEventListener("click", async () => {
        try {
            await navigator.clipboard.writeText(url.value);
            if (dialog.open) message.textContent = "Đã sao chép link QR. Gửi riêng cho khách.";
        } catch {
            url.focus();
            url.select();
            message.textContent = "Link đã được chọn. Hãy sao chép và gửi riêng cho khách.";
        }
    });
    retry.addEventListener("click", async () => {
        if (busy) return;
        busy = true;
        retry.disabled = true;
        message.textContent = "Đang lấy link QR…";
        try {
            const result = await request("POST", { resumePayment: true, bookingCode: pendingCode });
            retry.disabled = false;
            showResult(result);
        } catch (error) {
            message.textContent = error.message;
        } finally {
            busy = false;
            retry.disabled = false;
        }
    });

    document.addEventListener("click", async (event) => {
        const pressed = event.target.closest("[data-booking-continuation]");
        if (!pressed || busy) return;
        trigger = pressed;
        bookingId = pressed.dataset.bookingContinuation;
        const expectedCode = pressed.dataset.continuationPaymentCode;
        packages = [];
        pendingCode = "";
        requestId = "";
        lastSelection = "";
        form.reset();
        fields.hidden = true;
        retry.hidden = true;
        link.hidden = true;
        url.value = "";
        one("[data-continuation-title]").textContent = "";
        message.textContent = "Đang kiểm tra gói khả dụng…";
        const active = ++version;
        if (!dialog.open) dialog.showModal();
        try {
            const result = await request("GET");
            if (active !== version) return;
            if (expectedCode && result.pendingBookingCode !== expectedCode) {
                message.textContent = "Gói ở tiếp không còn chờ thanh toán. Kiểm tra lại danh sách đặt phòng.";
                return;
            }
            if (result.pendingBookingCode) {
                pendingCode = result.pendingBookingCode;
                one("[data-continuation-title]").textContent = `Gói ở tiếp ${result.pendingBookingCode}`;
                message.textContent = "Gói này đang chờ thanh toán. Có thể lấy lại link QR của cùng đơn.";
                retry.hidden = false;
                return;
            }
            packages = result.packages;
            one("[data-continuation-title]").textContent = `${result.roomName} · Ở tiếp đơn ${result.bookingCode}`;
            form.elements.packageId.innerHTML = packages.map((item) => `<option value="${escape(item.id)}">${escape(item.name)} · ${cash(item.price)}</option>`).join("");
            setMissingIdentity(result.missingIdentitySides || [], result.identityBookingCode);
            fields.hidden = !packages.length;
            message.textContent = packages.length
                ? "Gói mới bắt đầu lúc trả gói trước. Máy chủ sẽ kiểm tra lại lịch khi lưu."
                : "Không có gói phù hợp bắt đầu đúng giờ trả hoặc khung giờ tiếp theo đã bận.";
            renderSummary();
        } catch (error) {
            if (active === version) message.textContent = error.message;
        }
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const selected = packages.find((item) => item.id === form.elements.packageId.value);
        if (busy || fields.hidden || !selected || !form.reportValidity()) return;
        const payload = new FormData(form);
        const online = form.elements.payment.value === "QR";
        const paymentStatus = online ? "UNPAID" : form.elements.payment.value;
        const paymentMethod = online ? "bank_transfer" : form.elements.paymentMethod.value;
        payload.delete("payment");
        payload.set("paymentStatus", paymentStatus);
        payload.set("paymentMethod", paymentMethod);
        payload.set("guestCheckout", online ? "1" : "0");
        payload.set("expectedPrice", String(selected.price));
        const selection = JSON.stringify([form.elements.packageId.value, paymentStatus, paymentMethod, online]);
        if (!requestId || lastSelection !== selection) {
            requestId = crypto.randomUUID().replaceAll("-", "");
            lastSelection = selection;
        }
        payload.set("requestId", requestId);
        busy = true;
        const controls = [...form.querySelectorAll("input,select,button")];
        const disabled = controls.map((control) => control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        message.textContent = online ? "Đang lưu gói và tạo link QR…" : "Đang lưu gói ở tiếp…";
        try {
            showResult(await request("POST", payload));
        } catch (error) {
            message.textContent = error.message;
        } finally {
            busy = false;
            controls.forEach((control, index) => { control.disabled = disabled[index]; });
            renderSummary();
        }
    });
})();
