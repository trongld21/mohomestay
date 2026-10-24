(() => {
    "use strict";
    const root = document.querySelector("[data-expenses]");
    if (!root) return;
    const find = (selector) => root.querySelector(selector);
    const filter = find("[data-expense-filter]");
    const dialog = find("[data-expense-dialog]");
    const form = find("[data-expense-form]");
    const status = find("[data-expense-status]");
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[c]);
    const cash = (value) => new Intl.NumberFormat("vi-VN").format(Number(value)) + "đ";
    const date = (value) => new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", dateStyle: "short", timeStyle: "short" }).format(new Date(value.replace(" ", "T") + "Z"));
    let page = 1;
    let requestId = "";
    let saving = false;
    let revision = 0;

    async function request(url, payload) {
        const response = await fetch(url, payload ? {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": window.LANG_HOME.csrf },
            body: JSON.stringify(payload),
        } : { cache: "no-store" });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Không thể tải phiếu chi.");
        return data;
    }

    async function load() {
        const current = ++revision;
        status.textContent = "Đang tải…";
        const params = new URLSearchParams(new FormData(filter));
        params.set("page", String(page));
        try {
            const data = await request("/api/admin/expenses?" + params);
            if (current !== revision) return;
            find("[data-expense-totals]").textContent = `Tổng phiếu: ${data.count} · Tổng đã chi: ${cash(data.total)} · Chi dòng tiền: ${cash(data.cashflow)}`;
            find("[data-expense-list]").innerHTML = data.vouchers.length ? `<table class="w-full min-w-[800px] text-left text-sm"><thead class="bg-[#eee6da]"><tr>${["Mã phiếu / Thời gian", "Loại chi", "Giá trị", "Người nhận", "Ghi chú", "Dòng tiền"].map((label) => `<th class="p-3">${label}</th>`).join("")}</tr></thead><tbody>${data.vouchers.map((row) => `<tr class="border-b border-[#e5dacb]"><td class="p-3"><strong>${escape(row.voucher_code)}</strong><br>${escape(date(row.spent_at))}</td><td class="p-3">${escape(row.category)}</td><td class="p-3 font-semibold">${cash(row.amount)}</td><td class="p-3">${escape(row.recipient || "—")}</td><td class="max-w-xs whitespace-pre-wrap break-words p-3">${escape(row.note || "—")}</td><td class="p-3">${Number(row.include_cashflow) === 1 ? "Có" : "Không"}</td></tr>`).join("")}</tbody></table>` : '<p class="py-4 text-sm text-stone-600">Chưa có phiếu chi trong khoảng ngày đã chọn.</p>';
            find("[data-expense-page]").textContent = `Trang ${page} · ${data.count} phiếu`;
            find("[data-expense-prev]").disabled = page <= 1;
            find("[data-expense-next]").disabled = page * 30 >= data.count;
            status.textContent = "";
        } catch (error) {
            if (current === revision) status.textContent = error.message;
        }
    }

    filter.addEventListener("submit", (event) => {
        event.preventDefault();
        page = 1;
        load();
    });
    find("[data-expense-prev]").addEventListener("click", () => { page--; load(); });
    find("[data-expense-next]").addEventListener("click", () => { page++; load(); });
    find("[data-expense-new]")?.addEventListener("click", () => {
        form.reset();
        requestId = [...crypto.getRandomValues(new Uint8Array(16))].map((value) => value.toString(16).padStart(2, "0")).join("");
        const parts = Object.fromEntries(new Intl.DateTimeFormat("sv-SE", {
            timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23",
        }).formatToParts(new Date()).map(({ type, value }) => [type, value]));
        form.elements.spentAt.value = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
        find("[data-expense-error]").textContent = "";
        dialog.showModal();
    });
    root.querySelectorAll("[data-expense-close]").forEach((button) => button.addEventListener("click", () => {
        if (!saving) dialog.close();
    }));
    dialog?.addEventListener("cancel", (event) => {
        if (saving) event.preventDefault();
    });
    form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving || !form.reportValidity()) return;
        const payload = Object.fromEntries(new FormData(form));
        payload.amount = Number(payload.amount);
        payload.includeCashflow = form.elements.includeCashflow.checked;
        payload.requestId = requestId;
        saving = true;
        const controls = [...form.querySelectorAll("input,textarea,button")];
        controls.forEach((control) => { control.disabled = true; });
        form.setAttribute("aria-busy", "true");
        try {
            const data = await request("/api/admin/expenses", payload);
            dialog.close();
            await load();
            status.textContent = `Đã lưu phiếu ${data.voucher.voucher_code}.`;
        } catch (error) {
            find("[data-expense-error]").textContent = error.message + " Thử lại cùng phiếu sẽ không lưu trùng.";
        } finally {
            saving = false;
            controls.forEach((control) => { control.disabled = false; });
            form.removeAttribute("aria-busy");
        }
    });
    load();
})();
