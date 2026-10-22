(() => {
    const root = document.querySelector("[data-door-codes]");
    if (!root) return;
    const one = (selector) => root.querySelector(selector);
    const icon = (name) =>
        `<svg class="admin-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-${name}"></use></svg>`;
    const form = one("[data-door-form]");
    const dialog = one("[data-door-dialog]");
    const list = one("[data-door-list]");
    const notice = one("[data-door-notice]");
    const errorBox = one("[data-door-error]");
    const title = one("#door-form-title");
    const save = one("[data-door-save]");
    const refresh = one("[data-door-refresh]");
    const add = one("[data-door-add]");
    const search = one("[data-door-search]");
    const filter = one("[data-door-filter]");
    const discard = one("[data-door-discard]");
    const labels = {
        ACTIVE: "Đang dùng",
        DISABLED: "Đã tắt",
        SCHEDULED: "Chưa đến hạn",
        EXPIRED: "Hết hạn",
    };
    const periods = { DAY: "Ngày", WEEK: "Tuần", MONTH: "Tháng" };
    const escape = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (char) =>
                ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[char],
        );
    const localTime = (utc) =>
        new Date(utc.replace(" ", "T") + "Z").toLocaleString("vi-VN", {
            timeZone: "Asia/Ho_Chi_Minh",
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
            hour12: false,
        });
    const inputTime = (utc) =>
        new Date(new Date(utc.replace(" ", "T") + "Z").getTime() + 7 * 3600000)
            .toISOString()
            .slice(0, 16);
    let pendingLoad = null;
    let codes = [],
        loading = false,
        loaded = false,
        saving = false,
        dirty = false;
    let returnFocus = null,
        statusSignature = "";

    async function request(url, method = "GET", body) {
        const response = await fetch(url, {
            method,
            cache: "no-store",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": window.LANG_HOME.csrf },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error("Máy chủ chưa phản hồi đúng. Vui lòng thử lại.");
        }
        if (!response.ok) throw new Error(data.error || "Không thể kết nối máy chủ.");
        return data;
    }
    function codeStatus(row) {
        const now = Date.now();
        const start = new Date(row.starts_at.replace(" ", "T") + "Z").getTime();
        const end = new Date(row.ends_at.replace(" ", "T") + "Z").getTime();
        return now >= end
            ? "EXPIRED"
            : row.status === "DISABLED"
              ? "DISABLED"
              : now < start
                ? "SCHEDULED"
                : "ACTIVE";
    }
    function statusBadge(row) {
        const status = codeStatus(row);
        return `<span class="door-status-badge" data-status="${status}">${labels[status]}</span>`;
    }
    const pagination = MoPagination.create("door-codes", render);
    let deleting = false;
    function render() {
        if (!loaded) return;
        statusSignature = codes.map((row) => row.id + ":" + codeStatus(row)).join("|");
        one("[data-door-count]").textContent = codes.length;
        const totals = Object.keys(labels).map((status) => [
            status,
            codes.filter((row) => codeStatus(row) === status).length,
        ]);
        one("[data-door-summary]").textContent = totals
            .map(([status, count]) => `${count} ${labels[status].toLowerCase()}`)
            .join(" · ");
        const query = search.value.trim().toLowerCase();
        const filtered = codes.filter(
            (row) =>
                row.code.toLowerCase().includes(query) &&
                (filter.value === "all" || codeStatus(row) === filter.value),
        );
        if (!filtered.length) {
            const empty = codes.length === 0;
            list.innerHTML = `<div class="door-empty" role="status">
                <span class="door-empty-icon">${icon("key")}</span>
                <h3>${empty ? "Chưa có mã mở cửa" : "Không tìm thấy mã phù hợp"}</h3>
                <p>${empty ? "Thêm mã đầu tiên và thiết lập thời gian sử dụng cho khách." : "Thử tìm bằng mã khác hoặc bỏ bộ lọc trạng thái."}</p>
                <button type="button" class="button ${empty ? "" : "outline"}" ${empty ? "data-door-add" : "data-door-clear"}>${empty ? icon("plus") + " Thêm mã đầu tiên" : "Xóa bộ lọc"}</button>
            </div>`;
            return;
        }
        const view = pagination.slice(filtered);
        list.innerHTML = `<div class="admin-table-scroll" role="region" aria-label="Danh sách mã mở cửa" tabindex="0"><table class="admin-booking-table door-code-table"><thead><tr>
            <th scope="col">Mã mở cửa / Loại</th><th scope="col">Bắt đầu hiệu lực</th><th scope="col">Kết thúc hiệu lực</th><th scope="col">Trạng thái</th><th scope="col">Thao tác</th>
            </tr></thead><tbody>${view.items
                .map(
                    (row) => `<tr>
                <td class="door-code-cell"><strong class="door-code-value">${escape(row.code)}</strong><span>Theo ${periods[row.period].toLowerCase()}</span></td>
                <td class="door-time-cell"><span class="door-mobile-label">Bắt đầu hiệu lực</span><span>${escape(localTime(row.starts_at))}</span></td><td class="door-end-cell"><span class="door-mobile-label">Kết thúc hiệu lực</span><span>${escape(localTime(row.ends_at))}</span></td>
                <td class="door-status-cell">${statusBadge(row)}</td>
                <td class="door-actions-cell"><div class="door-row-actions"><button type="button" data-door-edit="${escape(row.id)}" class="icon-button" title="Chỉnh sửa" aria-label="Sửa mã ${escape(row.code)}">${icon("pencil")}</button><button type="button" data-door-delete="${escape(row.id)}" class="icon-button door-delete-button" title="Xóa mã" aria-label="Xóa mã ${escape(row.code)}">${icon("trash")}</button></div></td>
            </tr>`,
                )
                .join("")}</tbody></table></div>${pagination.render(view, "mã")}`;
    }
    async function load() {
        if (loading) return;
        loading = true;
        refresh.disabled = true;
        add.disabled = true;
        list.setAttribute("aria-busy", "true");
        notice.hidden = true;
        try {
            pendingLoad = request("/api/admin/door-codes");
            const data = await pendingLoad;
            const syncLabels = { PENDING: 'Chờ tạo mã', READY: 'Đã cấp mã TTLock', FAILED: 'Đồng bộ thất bại', UNKNOWN: 'Cần đối soát với khóa', REVOKED: 'Đã thu hồi' };
            const mailLabels = { PENDING: 'Chờ gửi', SENDING: 'Đang gửi', SENT: 'Đã gửi', FAILED: 'Gửi thất bại' };
            root.querySelector('[data-ttlock-status]').textContent = !data.ttlockEnabled
                ? 'TTLock chưa bật. Web đang dùng luồng email và mã thủ công hiện tại.'
                : data.ttlockConfigured ? 'TTLock đã bật và có đủ cấu hình. Cần kiểm tra thực tế trên khóa khi kích hoạt lần đầu.' : 'TTLock đang bật nhưng thiếu cấu hình. Email có mã đang chờ xử lý.';
            root.querySelector('[data-booking-access-list]').innerHTML = data.bookingCodes.length
                ? data.bookingCodes.map(row => `<article class="rounded-xl border border-stone-200 p-4">
                    <h3 class="font-semibold">${escape(row.booking_code)} · ${escape(row.guest_name)}</h3>
                    <p class="mt-2 text-sm">${escape(row.code_mode === 'GENERATED' && row.sync_status === 'REVOKED' ? 'Đã hết thời hạn' : syncLabels[row.sync_status] || row.sync_status)} · Email: ${escape(mailLabels[row.email_status] || row.email_status)}</p>
                    ${row.sync_status === 'READY' ? `<p class="mt-2 break-all font-mono text-xl font-semibold">${escape(row.code)}</p>` : ''}
                    <p class="mt-2 text-sm">${escape(localTime(row.starts_at))} → ${escape(localTime(row.ends_at))}</p>
                    ${row.sync_error ? `<p class="mt-2 text-sm text-red-800">${escape(row.sync_error)}</p>` : ''}
                    ${data.ttlockEnabled && data.ttlockConfigured && row.sync_status !== 'REVOKED' ? `<button type="button" data-access-retry="${escape(row.booking_id)}" class="mt-3 min-h-11 rounded-xl border border-stone-300 px-4 py-2 enabled:hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800 disabled:opacity-50">Đối soát / thử lại</button>` : ''}
                </article>`).join('') : '<p class="text-sm text-stone-600">Chưa có mã riêng theo đơn.</p>';
            codes = data.codes;
            loaded = true;
            render();
        } catch (error) {
            notice.textContent =
                error.message +
                (loaded ? " Đang hiển thị dữ liệu từ lần tải trước." : " Chọn Làm mới để thử lại.");
            notice.hidden = false;
            if (!loaded)
                list.innerHTML =
                    '<div class="door-empty"><h3>Chưa tải được danh sách mã</h3><p>Kiểm tra kết nối rồi thử tải lại.</p><button type="button" class="button outline" data-door-retry>Thử lại</button></div>';
        } finally {
            loading = false;
            refresh.disabled = false;
            add.disabled = false;
            list.setAttribute("aria-busy", "false");
        }
    }
    function validateTimes() {
        const { startsAt, endsAt } = form.elements;
        endsAt.setCustomValidity(
            startsAt.value && endsAt.value && endsAt.value <= startsAt.value
                ? "Thời điểm kết thúc phải sau thời điểm bắt đầu."
                : "",
        );
    }
    function openEditor(trigger, row = null) {
        returnFocus = trigger;
        form.reset();
        form.elements.id.value = row?.id || "";
        form.elements.code.value = row?.code || "";
        for (const key of ["period", "status"]) {
            form.elements[key].value = row?.[key] || (key === "period" ? "DAY" : "ACTIVE");
            form.elements[key].dispatchEvent(new Event("change", { bubbles: true }));
        }
        form.elements.startsAt.value = row ? inputTime(row.starts_at) : "";
        form.elements.endsAt.value = row ? inputTime(row.ends_at) : "";
        form.elements.endsAt.setCustomValidity("");
        title.textContent = row ? "Chỉnh sửa mã mở cửa" : "Thêm mã mở cửa";
        errorBox.hidden = true;
        discard.hidden = true;
        dirty = false;
        dialog.showModal();
        document.body.classList.add("dialog-open");
        form.elements.code.focus();
    }
    function requestClose() {
        if (saving) return;
        if (!dirty) return dialog.close();
        discard.hidden = false;
        one("[data-door-continue]").focus();
    }
    async function deleteCode(button) {
        if (deleting) return;
        const row = codes.find((item) => item.id === button.dataset.doorDelete);
        if (!row) return;
        deleting = true;
        try {
            const confirmed = await window.LangUI["confirm"]({
                title: "Xóa mã mở cửa?",
                message: `Mã ${row.code} sẽ bị xóa khỏi hệ thống và không còn được gửi trong các email đặt phòng mới. Bạn không thể hoàn tác thao tác này.`,
                confirmLabel: "Xóa mã",
                cancelLabel: "Giữ lại",
                tone: "danger",
            });
            if (!confirmed) return;
            button.disabled = true;
            await request("/api/admin/door-codes/" + encodeURIComponent(row.id), "DELETE");
            codes = codes.filter((item) => item.id !== row.id);
            render();
            add.focus();
            window.LangUI.toast({ type: "success", title: "Đã xóa mã mở cửa" });
        } catch (error) {
            button.disabled = false;
            window.LangUI.toast({
                type: "error",
                title: "Chưa thể xóa mã",
                message: error.message,
            });
        } finally {
            deleting = false;
        }
    }
    root.addEventListener("click", (event) => {
        const trigger = event.target.closest("button");
        if (!trigger) return;
        if (trigger.hasAttribute("data-door-delete")) deleteCode(trigger);
        if (trigger.hasAttribute("data-door-add")) openEditor(trigger);
        if (trigger.hasAttribute("data-door-edit")) {
            const row = codes.find((item) => item.id === trigger.dataset.doorEdit);
            if (row) openEditor(trigger, row);
        }
        if (trigger.hasAttribute("data-door-clear")) {
            pagination.reset();
            search.value = "";
            filter.value = "all";
            filter.dispatchEvent(new Event("change", { bubbles: true }));
            search.focus();
        }
        if (trigger.hasAttribute("data-door-retry")) load();
        if (trigger.hasAttribute("data-door-close")) requestClose();
    });
    dialog.addEventListener("cancel", (event) => {
        event.preventDefault();
        requestClose();
    });
    dialog.addEventListener("click", (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (
            event.clientX < bounds.left ||
            event.clientX > bounds.right ||
            event.clientY < bounds.top ||
            event.clientY > bounds.bottom
        )
            requestClose();
    });
    dialog.addEventListener("close", () => {
        dirty = false;
        document.body.classList.remove("dialog-open");
        if (returnFocus?.isConnected) returnFocus.focus();
        else add.focus();
    });
    one("[data-door-continue]").addEventListener("click", () => {
        discard.hidden = true;
        form.elements.code.focus();
    });
    one("[data-door-discard-confirm]").addEventListener("click", () => dialog.close());
    form.addEventListener("input", () => {
        dirty = true;
        discard.hidden = true;
        validateTimes();
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving) return;
        validateTimes();
        if (!form.reportValidity()) return;
        const body = Object.fromEntries(new FormData(form));
        const id = body.id;
        delete body.id;
        saving = true;
        const controls = [
            ...form.querySelectorAll("input, select, button"),
            ...dialog.querySelectorAll("[data-door-close]"),
        ];
        controls.forEach((control) => (control.disabled = true));
        save.textContent = "Đang lưu…";
        errorBox.hidden = true;
        try {
            await request(
                "/api/admin/door-codes" + (id ? "/" + encodeURIComponent(id) : ""),
                id ? "PATCH" : "POST",
                body,
            );
            dirty = false;
            dialog.close();
            if (loading) await pendingLoad.catch(() => {});
            await load();
            add.focus();
            window.LangUI.toast({
                type: "success",
                title: "Đã lưu mã mở cửa",
                message: "Thời gian và trạng thái đã được cập nhật.",
            });
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
            errorBox.scrollIntoView({ block: "nearest" });
        } finally {
            saving = false;
            controls.forEach((control) => (control.disabled = false));
            save.innerHTML = icon("device-floppy") + " Lưu mã";
        }
    });
    search.addEventListener("input", () => {
        pagination.reset();
        render();
    });
    filter.addEventListener("change", () => {
        pagination.reset();
        render();
    });
    refresh.addEventListener("click", load);
    window.addEventListener("beforeunload", (event) => {
        if (dialog.open && dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    root.querySelector('[data-booking-access-list]').addEventListener('click', async event => {
        const button = event.target.closest('[data-access-retry]');
        if (!button) return;
        button.disabled = true;
        try {
            await request(`/api/admin/booking-access/${encodeURIComponent(button.dataset.accessRetry)}/retry`, 'POST', {});
            await load();
        } catch (error) {
            notice.textContent = error.message;
            notice.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
    // Update only the status text, preserving keyboard focus on edit controls.
    setInterval(() => {
        const next = codes.map((row) => row.id + ":" + codeStatus(row)).join("|");
        if (next === statusSignature) return;
        const focusedId = document.activeElement?.dataset.doorEdit;
        render();
        if (focusedId)
            [...list.querySelectorAll("[data-door-edit]")]
                .find((button) => button.dataset.doorEdit === focusedId)
                ?.focus();
    }, 1000);
    load();
})();
