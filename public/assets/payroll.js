(() => {
    "use strict";

    const root = document.querySelector("[data-payroll]");
    if (!root) return;
    const find = (selector) => root.querySelector(selector);
    const personnel = root.dataset.screen === "personnel";
    const list = find("[data-list]");
    const createPanel = find("[data-create]");
    const createForm = find("[data-create-form]");
    const filter = find("[data-filter]");
    const detailPanel = find("[data-detail]");
    const editor = find("[data-entry-editor]");
    const entryForm = find("[data-entry-form]");
    const money = (value) => new Intl.NumberFormat("vi-VN").format(Number(value) || 0) + " đ";
    const hours = (value) => new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 }).format(Number(value) / 60);
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
    const date = (value) => value ? String(value).split("-").reverse().join("/") : "—";
    const numericFields = ["hourly_rate", "monthly_salary", "expected_days", "paid_leave_days", "weekend_bonus_rate", "version"];
    let employees = [];
    let periods = [];
    let detail = null;
    let dirty = false;
    let busy = false;
    let requestNumber = 0;
    let resolveQuestion = null;
    let previousFocus = null;
    let importPreview = null;
    let importFile = null;
    let periodPage = Math.max(1, Number(new URLSearchParams(window.location.search).get("page")) || 1);
    let renamingPeriod = null;
    let reviewQuick = "all";
    let showAddEmployee = false;

    const icon = (name) => `<svg class="admin-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-${name}"></use></svg>`;
    const attendance = personnel ? null : window.MoPayrollAttendance.create(root, {
        async onSave(values) {
            detail = await api(null, { action: "entry/save", ...values });
            renderDetail();
            notify("Đã lưu chấm công và tính lại bảng lương.");
        },
    });
    const excelImport = personnel ? null : window.MoPayrollExcelImport.create(root, {
        getDetail: () => detail,
        askConfirmation: question,
        notify,
        async onSave(values) {
            if (dirty) throw new Error("Lưu hoặc đóng phần đang sửa trước khi xác nhận nhập Excel.");
            detail = await api(null, { action: "entry/save", ...values });
            clearImportPreview();
            dirty = false;
            editor.hidden = true;
            renderDetail();
            notify("Đã nhập Excel và tính lại bảng lương.");
        },
    });
    const actionsTrigger = find("[data-period-actions]");
    const actionsMenu = find("[data-period-actions-menu]");
    let focusLastAction = false;

    function positionActions() {
        if (!actionsMenu?.matches(":popover-open")) return;
        const rect = actionsTrigger.getBoundingClientRect();
        const width = window.visualViewport?.width || window.innerWidth;
        const height = window.visualViewport?.height || window.innerHeight;
        actionsMenu.style.maxHeight = Math.max(120, height - 24) + "px";
        actionsMenu.style.left = Math.max(12, Math.min(rect.right - actionsMenu.offsetWidth, width - actionsMenu.offsetWidth - 12)) + "px";
        actionsMenu.style.top = Math.max(12, Math.min(rect.bottom + 8, height - actionsMenu.offsetHeight - 12)) + "px";
    }

    function closeActions(focus = false) {
        if (!actionsMenu?.matches(":popover-open")) return;
        actionsMenu.hidePopover();
        if (focus) actionsTrigger.focus();
    }

    actionsMenu?.addEventListener("toggle", (event) => {
        const open = event.newState === "open";
        actionsTrigger.setAttribute("aria-expanded", String(open));
        if (open) {
            positionActions();
            const items = [...actionsMenu.querySelectorAll('[role="menuitem"]')];
            items[focusLastAction ? items.length - 1 : 0]?.focus();
            focusLastAction = false;
        }
    });
    actionsTrigger?.addEventListener("keydown", (event) => {
        if (!["ArrowDown", "ArrowUp"].includes(event.key)) return;
        event.preventDefault();
        focusLastAction = event.key === "ArrowUp";
        if (!actionsMenu.matches(":popover-open")) actionsMenu.showPopover();
        positionActions();
        const items = [...actionsMenu.querySelectorAll('[role="menuitem"]')];
        items[event.key === "ArrowUp" ? items.length - 1 : 0]?.focus();
    });
    actionsMenu?.addEventListener("keydown", (event) => {
        const items = [...actionsMenu.querySelectorAll('[role="menuitem"]:not(:disabled)')];
        const index = items.indexOf(document.activeElement);
        if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
            event.preventDefault();
            const next = event.key === "Home" ? 0 : event.key === "End" ? items.length - 1 : (index + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
            items[next]?.focus();
        } else if (event.key === "Escape") {
            event.preventDefault();
            closeActions(true);
        } else if (event.key === "Tab") {
            closeActions(true);
        }
    });
    window.addEventListener("resize", positionActions);
    window.addEventListener("scroll", positionActions, true);

    function renderPeriods(rows) {
        const view = window.MoPagination.windowFor(rows, periodPage, 10);
        periodPage = view.page;
        updateUrl({ page: view.page > 1 ? String(view.page) : "" });
        const records = view.items.map((row) => `<article class="hr-period-row">
            <span class="hr-period-icon">${icon("file-description")}</span>
            <div class="hr-period-info"><h3>${escape(row.name)}</h3>
                <p class="hr-muted text-sm">${date(row.start_date)} – ${date(row.end_date)}</p>
                <p class="hr-muted text-xs">Tạo ngày ${date(String(row.created_at).slice(0, 10))} / ${Number(row.employee_count)} nhân viên</p>
                ${renamingPeriod === row.id ? `<form class="hr-period-rename" data-rename-period-form data-period-id="${escape(row.id)}"><label class="hr-label">Tên kỳ lương mới<input class="hr-field" name="name" value="${escape(row.name)}" maxlength="150" required></label><button class="hr-button" type="submit">Lưu tên</button><button class="hr-button" type="button" data-cancel-rename>Hủy</button></form>` : ""}
            </div>
            <div class="hr-period-totals"><strong class="hr-income tabular-nums">${money(row.net_salary)}</strong></div>
            <div class="hr-actions hr-period-actions"><button class="hr-button hr-open-period" type="button" data-open-period="${escape(row.id)}" aria-label="Mở ${escape(row.name)}">Mở chi tiết ${icon("arrow-right")}</button>
                <button class="hr-button hr-icon-button hr-info" type="button" data-rename-period="${escape(row.id)}" aria-label="Đổi tên ${escape(row.name)}" title="Đổi tên">${icon("pencil")}</button><button class="hr-button hr-icon-button hr-danger" type="button" data-delete-period="${escape(row.id)}" aria-label="Xóa kỳ lương ${escape(row.name)}" title="Xóa kỳ lương">${icon("trash")}</button>
            </div></article>`).join("");
        const pageButton = (page, label, content, disabled = false) => `<button class="hr-button hr-icon-button" type="button" data-period-page="${page}" aria-label="${label}" ${disabled ? "disabled" : ""} ${page === view.page ? 'aria-current="page"' : ""}>${content}</button>`;
        const pagination = `<footer class="hr-period-pagination"><span class="hr-muted text-sm">${view.start}–${view.end} / ${view.count} kỳ lương</span><nav class="hr-actions" aria-label="Phân trang kỳ lương">${pageButton(view.page - 1, "Trang trước", icon("arrow-left"), view.page === 1)}${window.MoPagination.pages(view.page, view.total).map((page) => page === "…" ? '<span aria-hidden="true">…</span>' : pageButton(page, "Trang " + page, page)).join("")}${pageButton(view.page + 1, "Trang sau", icon("arrow-right"), view.page === view.total)}</nav></footer>`;
        list.innerHTML = `<header class="hr-period-list-heading"><h2>Các kỳ lương</h2><p class="hr-muted text-sm">${rows.length} kỳ lương${rows.length !== periods.length ? " phù hợp bộ lọc" : ""}</p></header>${records}${pagination}`;
    }

    function clearImportPreview(resetFile = true) {
        importPreview = null;
        importFile = null;
        const sourceLabel = find("[data-text-source-label]");
        if (sourceLabel) sourceLabel.hidden = true;
        const preview = find("[data-timesheet-preview]");
        if (preview) {
            preview.hidden = true;
            preview.replaceChildren();
            if (resetFile) find("[data-timesheet-file]").value = "";
        }
    }

    function notify(message, error = false) {
        window.LangUI.toast({
            type: error ? "error" : "success",
            title: error ? "Không thể hoàn tất" : "Thành công",
            message,
            persistent: error,
        });
    }

    async function api(resource, payload) {
        const response = await fetch("/api/admin/payroll" + (resource ? "?" + new URLSearchParams(resource) : ""), {
            method: payload ? "POST" : "GET",
            headers: payload ? { "Content-Type": "application/json", "X-CSRF-Token": root.dataset.csrf } : {},
            credentials: "same-origin",
            body: payload ? JSON.stringify(payload) : undefined,
        });
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error("Máy chủ chưa trả được dữ liệu. Kiểm tra kết nối và thử lại.");
        }
        if (!response.ok) throw new Error(data.error || "Không thể thực hiện thao tác.");
        return data;
    }

    async function perform(action) {
        if (busy) return;
        busy = true;
        const upload = find("[data-timesheet-file]");
        const sourceSelect = find("[data-text-source]");
        if (sourceSelect) sourceSelect.disabled = true;
        if (upload) upload.disabled = true;
        const buttons = [...root.querySelectorAll("button")].filter((button) => !button.disabled);
        buttons.forEach((button) => { button.disabled = true; });
        root.setAttribute("aria-busy", "true");
        try {
            await action();
        } catch (error) {
            notify(error.message, true);
        } finally {
            busy = false;
            if (upload) upload.disabled = false;
            if (sourceSelect) sourceSelect.disabled = false;
            buttons.forEach((button) => { button.disabled = false; });
            root.removeAttribute("aria-busy");
            if (detail) updateAddEmployeeState();
        }
    }

    function question(message) {
        previousFocus = document.activeElement;
        find("[data-confirm-text]").textContent = message;
        find("[data-confirm]").hidden = false;
        find("[data-confirm-cancel]").focus();
        return new Promise((resolve) => { resolveQuestion = resolve; });
    }

    function answer(value) {
        find("[data-confirm]").hidden = true;
        if (resolveQuestion) resolveQuestion(value);
        resolveQuestion = null;
        previousFocus?.focus();
    }

    async function mayDiscard() {
        if (!dirty) return true;
        return question("Bạn có thay đổi chưa lưu. Bỏ các thay đổi này?");
    }

    function formValues(form) {
        const values = Object.fromEntries(new FormData(form));
        numericFields.forEach((key) => {
            if (key in values) values[key] = Number(values[key]);
        });
        return values;
    }

    function fill(form, values) {
        form.reset();
        Object.entries(values).forEach(([key, value]) => {
            const field = form.elements.namedItem(key);
            if (!field) return;
            if (field.type === "checkbox") field.checked = Boolean(value);
            else field.value = value ?? "";
        });
    }

    function updateUrl(values) {
        const url = new URL(window.location.href);
        Object.entries(values).forEach(([key, value]) => {
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        });
        history.replaceState(null, "", url);
    }

    function renderList() {
        const q = filter.elements.q.value.trim().toLocaleLowerCase("vi-VN");
        const selectedStatus = filter.elements.status?.value || "";
        const rows = (personnel ? employees : periods).filter((row) => {
            const text = personnel ? [row.name, row.code, row.role, row.phone, row.email].join(" ") : row.name;
            return (!selectedStatus || row.status === selectedStatus) && text.toLocaleLowerCase("vi-VN").includes(q);
        });
        if (!rows.length) {
            if (!personnel) {
                periodPage = 1;
                updateUrl({ page: "" });
            }
            if ((personnel ? employees : periods).length === 0) {
                list.innerHTML = `<div class="hr-empty">
                    <h2>${personnel ? "Chưa có nhân viên" : "Chưa có kỳ lương"}</h2>
                    <p class="hr-muted">${personnel ? "Thêm hồ sơ đầu tiên để quản lý vị trí, mức lương và lập bảng lương cho đội ngũ Mơ." : "Tạo kỳ lương đầu tiên để tổng hợp ca làm, phụ cấp và các khoản khấu trừ của nhân viên."}</p>
                    <button class="hr-button hr-primary" type="button" data-start-create>${personnel ? "Thêm nhân viên đầu tiên" : "Tạo kỳ lương đầu tiên"}</button>
                </div>`;
                return;
            }
            list.innerHTML = `<p class="hr-state">${(personnel ? employees : periods).length ? "Không có kết quả phù hợp. Thử đổi bộ lọc." : (personnel ? "Chưa có nhân viên. Chọn Thêm nhân viên để bắt đầu." : "Chưa có kỳ lương. Chọn Tạo kỳ lương để bắt đầu.")}</p>`;
            return;
        }
        if (!personnel) {
            renderPeriods(rows);
            return;
        }
        const head = "<th>Mã / nhân viên</th><th>Vị trí / liên hệ</th><th>Mức lương</th><th>Trạng thái</th><th>Thao tác</th>";
        const body = rows.map((row) => `<tr>
            <td data-label="Nhân viên"><div><strong>${escape(row.name)}</strong><div class="hr-muted mt-1">${escape(row.code)}</div></div></td>
            <td data-label="Vị trí / liên hệ"><div>${escape(row.role || "Chưa có vị trí")}<div class="hr-muted mt-1">${escape(row.phone || row.email || "Chưa có liên hệ")}</div></div></td>
            <td data-label="Mức lương" class="tabular-nums whitespace-nowrap">${money(row.salary_type === "hourly" ? row.hourly_rate : row.monthly_salary)} / ${row.salary_type === "hourly" ? "giờ" : "tháng"}</td>
            <td data-label="Trạng thái"><span class="hr-tag" data-state="${row.status === "active" ? "active" : "inactive"}">${row.status === "active" ? "Đang làm" : "Đã nghỉ việc"}</span></td>
            <td data-label="Thao tác"><div class="hr-actions"><button class="hr-button hr-icon-button hr-info" type="button" data-edit-employee="${escape(row.id)}" aria-label="Sửa hồ sơ ${escape(row.name)}" title="Sửa hồ sơ">${icon("pencil")}</button><button class="hr-button hr-icon-button hr-danger" type="button" data-delete-employee="${escape(row.id)}" aria-label="Xóa nhân viên ${escape(row.name)}" title="Xóa nhân viên">${icon("trash")}</button></div></td>
        </tr>`).join("");
        list.innerHTML = `<table class="hr-table"><caption class="sr-only">Danh sách nhân sự</caption><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    }

    async function loadList() {
        list.innerHTML = '<p class="hr-state">Đang tải dữ liệu…</p>';
        try {
            const data = await api({ resource: personnel ? "employees" : "periods" });
            if (personnel) employees = data.employees;
            else periods = data.periods;
            renderList();
        } catch (error) {
            list.innerHTML = '<p class="hr-state">Chưa tải được dữ liệu. Chọn Tải lại để thử lại.</p>';
            throw error;
        }
    }

    function updateAddEmployeeState() {
        find("[data-add-employee]").hidden = !showAddEmployee;
        find("[data-show-add]").setAttribute("aria-expanded", String(showAddEmployee));
        find("[data-add-employee] button[type=submit]").disabled = find("[data-add-employee] select").options.length <= 1;
    }

    function renderLedger() {
        if (!detail) return;
        const { period, entries } = detail;
        const query = find("[data-review-search]").value.trim().toLocaleLowerCase("vi-VN");
        const role = find("[data-review-role]").value;
        const sort = find("[data-review-sort]").value;
        const missing = (entry) => entry.details.shifts.length === 0;
        const matchesQuick = (entry) => reviewQuick === "all" || (reviewQuick === "missing" ? missing(entry) : entry.details.salary_type === reviewQuick);
        const rows = entries.filter((entry) => (!role || entry.role === role) && matchesQuick(entry)
            && [entry.employee_name, entry.employee_code, entry.role].join(" ").toLocaleLowerCase("vi-VN").includes(query));
        rows.sort((left, right) => (sort === "name" ? 0 : Number(right[sort === "salary" ? "net_salary" : "minutes"]) - Number(left[sort === "salary" ? "net_salary" : "minutes"]))
            || left.employee_name.localeCompare(right.employee_name, "vi"));
        find("[data-review-count]").textContent = `${rows.length}/${entries.length} nhân viên theo bộ lọc hiện tại`;
        find("[data-review-quick]").innerHTML = '<span class="hr-muted text-xs">Lọc nhanh</span>' + [
            ["all", "Tất cả", entries.length], ["missing", "Chưa có ca", entries.filter(missing).length],
            ["hourly", "Theo giờ", entries.filter((entry) => entry.details.salary_type === "hourly").length],
            ["monthly", "Lương tháng", entries.filter((entry) => entry.details.salary_type === "monthly").length],
        ].map(([key, label, count]) => `<button class="hr-button hr-review-chip" type="button" data-review-quick-value="${key}" aria-pressed="${reviewQuick === key}">${label} (${count})</button>`).join("");
        const sum = (key) => rows.reduce((total, entry) => total + Number(entry[key]), 0);
        find("[data-review-total]").innerHTML = `<span class="hr-muted text-sm">${rows.filter(missing).length} nhân viên chưa có ca trong kết quả đang hiển thị</span><div class="hr-actions"><span>Giờ làm: <strong>${hours(sum("minutes"))}h</strong></span><span>Ngày công: <strong>${sum("working_days")}</strong></span><span>Thực nhận: <strong class="hr-income">${money(sum("net_salary"))}</strong></span></div>`;
        find("[data-entries]").innerHTML = rows.length ? `<table class="hr-table hr-ledger-table"><caption class="sr-only">Chi tiết lương nhân viên</caption>
            <thead><tr><th>Nhân viên</th><th>Số giờ / ngày công</th><th>Lương/h</th><th title="Số giờ T7/CN để tính tiền cộng thêm">Bonus</th><th data-column="base" class="hr-money">Lương cơ bản</th><th data-column="allowance" class="hr-money">Phụ cấp / thưởng</th><th data-column="deduction" class="hr-money">Khấu trừ</th><th class="hr-money">Thực nhận</th><th>Thao tác</th></tr></thead>
            <tbody>${rows.map((entry) => `<tr>
                <td data-label="Nhân viên"><div class="grid gap-2"><strong>${escape(entry.employee_name)}</strong><span class="hr-muted text-xs">${escape(entry.employee_code)}${entry.role ? " / " + escape(entry.role) : ""}</span><span><span class="hr-tag" data-state="${entry.details.salary_type === "hourly" ? "active" : "draft"}">${entry.details.salary_type === "hourly" ? "Theo giờ" : "Lương tháng"}</span>${missing(entry) ? ' <span class="hr-tag" data-state="draft">Chưa có ca</span>' : ""}</span></div></td>
                <td data-label="Giờ / ngày công"><div><div class="hr-actions"><span class="hr-ledger-value">${hours(entry.minutes)}h</span><button class="hr-button hr-icon-button hr-positive" type="button" data-attendance-open="${escape(entry.employee_id)}" aria-label="Xem ca của ${escape(entry.employee_name)}" title="Xem ca làm">${icon("calendar")}</button></div><p class="hr-muted mt-2 text-xs">${Number(entry.working_days)} ngày công</p></div></td>
                <td data-label="Lương/h"><div><span class="hr-ledger-rate">${money(entry.details.salary_type === "hourly" ? entry.details.hourly_rate : entry.details.monthly_salary)}</span></div></td>
                <td data-label="Bonus"><label class="hr-label"><span class="sr-only">Giờ Bonus T7/CN của ${escape(entry.employee_name)}</span><input class="hr-field hr-bonus-hours" type="number" min="0" max="${(Number(entry.minutes) / 60).toFixed(2)}" step="0.01" value="${bonusHours(entry)}" data-bonus-hours="${escape(entry.employee_id)}" ${entry.details.salary_type !== "hourly" ? "disabled" : ""} title="Giờ T7/CN, cộng ${money(entry.details.weekend_bonus_rate ?? 1000)}/giờ. Để trống để tự tính lại từ ca."></label></td>
                <td data-column="base" data-label="Lương cơ bản" class="hr-money">${money(entry.base_salary)}</td>
                <td data-column="allowance" data-label="Phụ cấp / thưởng" class="hr-money hr-income">+${money(entry.allowance_total)}</td>
                <td data-column="deduction" data-label="Khấu trừ" class="hr-money hr-deduction">−${money(entry.deduction_total)}</td>
                <td data-label="Thực nhận" class="hr-money"><div><strong class="hr-income text-base">${money(entry.net_salary)}</strong><p class="hr-muted mt-2 text-xs">${entry.details.salary_type === "hourly" ? (entry.details.shifts.some((shift) => shift.hourly_rate !== undefined && shift.hourly_rate !== null) ? "Tính theo đơn giá từng ca" : hours(entry.minutes) + "h × " + money(entry.details.hourly_rate)) : "Lương tháng"}</p></div></td>
                <td data-label="Thao tác"><div class="hr-actions"><button class="hr-button hr-icon-button hr-info" type="button" data-edit-entry="${escape(entry.employee_id)}" aria-label="Nhập ca / sửa" title="Nhập ca / sửa">${icon("pencil")}</button><button class="hr-button hr-icon-button hr-danger" type="button" data-remove-entry="${escape(entry.employee_id)}" aria-label="Bỏ ${escape(entry.employee_name)} khỏi kỳ" title="Bỏ khỏi kỳ">${icon("trash")}</button></div></td>
            </tr>`).join("")}</tbody></table>` : `<p class="hr-state">${entries.length ? "Không có nhân viên phù hợp bộ lọc." : "Chưa có dòng lương. Chọn Thêm nhân viên để bắt đầu."}</p>`;
        root.querySelectorAll("[data-ledger-column]").forEach((input) => {
            find("[data-entries]").querySelectorAll(`[data-column="${input.dataset.ledgerColumn}"]`).forEach((cell) => { cell.hidden = !input.checked; });
        });
    }

    function bonusHours(entry) {
        if (entry.details.salary_type !== "hourly") return "0.00";
        if (entry.details.weekend_hours !== undefined && entry.details.weekend_hours !== null) return Number(entry.details.weekend_hours).toFixed(2);
        const minutes = entry.details.weekend_minutes ?? window.MoPayrollAttendance.validate(entry.details.shifts, detail.period).reduce((sum, row, index) => {
            const day = new Date(entry.details.shifts[index].date + "T12:00:00Z").getUTCDay();
            return sum + ((day === 0 || day === 6) && !row.message ? row.minutes : 0);
        }, 0);
        return (Number(minutes) / 60).toFixed(2);
    }

    function renderDetail() {
        excelImport.reset();
        const { period, entries } = detail;
        find("[data-list-section]").hidden = true;
        createPanel.hidden = true;
        const newButton = find("[data-new]");
        if (newButton) newButton.hidden = true;
        detailPanel.hidden = false;
        find("[data-period-name]").textContent = period.name;
        find("[data-period-count]").textContent = entries.length + " nhân viên";
        find("[data-export]").href = "/api/admin/payroll?resource=export&id=" + encodeURIComponent(period.id);
        const roleSelect = find("[data-review-role]");
        const previousRole = roleSelect.value;
        roleSelect.innerHTML = '<option value="">Tất cả vị trí</option>' + [...new Set(entries.map((entry) => entry.role).filter(Boolean))].sort((a, b) => a.localeCompare(b, "vi")).map((role) => `<option value="${escape(role)}">${escape(role)}</option>`).join("");
        roleSelect.value = previousRole;
        const meta = date(period.start_date) + " – " + date(period.end_date);
        find("[data-period-meta]").textContent = meta;
        const total = (key) => entries.reduce((sum, entry) => sum + Number(entry[key]), 0);
        find("[data-summary]").innerHTML = [
            ["Tổng thực nhận", money(total("net_salary"))], ["Nhân viên", entries.length],
            ["Giờ thực làm", hours(total("minutes")) + "h"], ["Ngày công", total("working_days")],
            ["Chưa có ca", entries.filter((entry) => entry.details.shifts.length === 0).length],
        ].map(([label, value]) => `<div><span class="hr-muted text-sm">${label}</span><strong>${value}</strong></div>`).join("");
        const options = employees.filter((employee) => !entries.some((entry) => entry.employee_id === employee.id)
            && employee.start_date <= period.end_date && (!employee.end_date || employee.end_date >= period.start_date));
        const select = find("[data-add-employee] select");
        select.innerHTML = '<option value="">Chọn nhân viên</option>' + options.map((employee) => `<option value="${escape(employee.id)}">${escape(employee.code + " — " + employee.name)}${employee.status === "inactive" ? " (đã nghỉ)" : ""}</option>`).join("");
        find("[data-add-employee] button[type=submit]").disabled = options.length === 0;
        renderLedger();
        updateAddEmployeeState();
    }

    async function openPeriod(id) {
        const sequence = ++requestNumber;
        const results = await Promise.allSettled([api({ resource: "period", id }), api({ resource: "employees" })]);
        if (sequence !== requestNumber) return;
        const failure = results.find((result) => result.status === "rejected");
        if (failure) throw failure.reason;
        detail = results[0].value;
        employees = results[1].value.employees;
        showAddEmployee = false;
        reviewQuick = "all";
        find("[data-review-search]").value = "";
        find("[data-review-role]").value = "";
        editor.hidden = true;
        dirty = false;
        renderDetail();
        updateUrl({ period: id });
    }

    function addRow(kind, values = {}) {
        const row = document.createElement("div");
        row.dataset.row = kind;
        if (kind === "shifts") {
            row.className = "hr-shift-row";
            row.dataset.breakMinutes = Number(values.break_minutes || 0);
            if (values.hourly_rate !== undefined && values.hourly_rate !== null) row.dataset.hourlyRate = Number(values.hourly_rate);
            row.innerHTML = `<label class="hr-label">Ngày<input class="hr-field" data-key="date" type="date" value="${escape(values.date || detail.period.start_date)}" min="${detail.period.start_date}" max="${detail.period.end_date}" required></label>
                <label class="hr-label">Giờ vào<input class="hr-field" data-key="in" type="time" value="${escape(values.in || "08:00")}" required></label>
                <label class="hr-label">Giờ ra<input class="hr-field" data-key="out" type="time" value="${escape(values.out || "17:00")}" required></label>
                <button class="hr-button hr-danger" type="button" data-remove-row aria-label="Xóa ca làm">Xóa ca</button>`;
        } else {
            row.className = "hr-adjustment-row";
            row.innerHTML = `<label class="hr-label">Nội dung<input class="hr-field" data-key="name" maxlength="150" value="${escape(values.name || "")}" required></label>
                <label class="hr-label">Số tiền (đ)<input class="hr-field" data-key="amount" type="number" min="0" max="1000000000" step="1" value="${Number(values.amount || 0)}" required></label>
                <button class="hr-button hr-danger" type="button" data-remove-row aria-label="Xóa khoản cộng trừ">Xóa khoản</button>`;
        }
        find(`[data-rows="${kind}"]`).append(row);
        window.MoPayrollDates.enhance(row);
    }

    function readRows(kind) {
        return [...find(`[data-rows="${kind}"]`).children].map((row) => {
            const values = Object.fromEntries([...row.querySelectorAll("[data-key]")].map((field) => [field.dataset.key, field.type === "number" ? Number(field.value) : field.value]));
            if (kind === "shifts") values.break_minutes = Number(row.dataset.breakMinutes || 0);
            if (kind === "shifts" && row.dataset.hourlyRate !== undefined) values.hourly_rate = Number(row.dataset.hourlyRate);
            return values;
        });
    }

    function editEntry(id) {
        const entry = detail.entries.find((row) => row.employee_id === id);
        if (!entry) return;
        clearImportPreview();
        fill(entryForm, { ...entry.details, employee_id: id });
        ["shifts", "allowances", "deductions"].forEach((kind) => {
            find(`[data-rows="${kind}"]`).replaceChildren();
            entry.details[kind].forEach((row) => addRow(kind, row));
        });
        find("[data-entry-name]").textContent = entry.employee_name + " — " + entry.employee_code;
        editor.hidden = false;
        find("[data-timesheet-upload]").hidden = false;
        entryForm.querySelectorAll("input, select, textarea, button").forEach((field) => {
            field.disabled = false;
        });
        dirty = false;
        editor.scrollIntoView({ block: "start" });
        entryForm.elements.salary_type.focus({ preventScroll: true });
    }

    async function mutate(action, values = {}) {
        detail = await api(null, { action, period_id: detail.period.id, version: Number(detail.period.version), ...values });
        clearImportPreview();
        dirty = false;
        editor.hidden = true;
        renderDetail();
        notify("Đã lưu và tính lại bảng lương.");
    }

    createForm.addEventListener("input", () => { dirty = true; });
    root.querySelectorAll("[data-review-search], [data-review-role], [data-review-sort], [data-ledger-column]").forEach((control) => {
        control.addEventListener("input", renderLedger);
    });
    root.addEventListener("change", async (event) => {
        const input = event.target.closest("[data-bonus-hours]");
        if (!input || !detail) return;
        const entry = detail.entries.find((row) => row.employee_id === input.dataset.bonusHours);
        if (!entry) return;
        if (busy || excelImport?.isBusy() || dirty) {
            input.value = bonusHours(entry);
            return notify("Lưu hoặc đóng phần đang sửa trước khi đổi giờ Bonus.", true);
        }
        if (!input.reportValidity()) return;
        const automatic = input.value === "";
        const value = automatic ? null : Number(input.value);
        if (!automatic && value === Number(bonusHours(entry))) return;
        if (automatic && entry.details.weekend_hours === undefined) {
            input.value = bonusHours(entry);
            return;
        }
        if (!await question(automatic ? `Tự tính lại giờ Bonus từ các ca T7/CN của “${entry.employee_name}”?` : `Lưu ${value} giờ Bonus T7/CN cho “${entry.employee_name}” và tính lại lương?`)) {
            input.value = bonusHours(entry);
            return;
        }
        const settings = { ...entry.details };
        if (automatic) delete settings.weekend_hours;
        else settings.weekend_hours = value;
        perform(async () => {
            try {
                await mutate("entry/save", { employee_id: entry.employee_id, details: settings });
            } catch (error) {
                input.value = bonusHours(entry);
                throw error;
            }
        });
    });
    entryForm?.addEventListener("input", () => { dirty = true; });
    find("[data-timesheet-file]")?.addEventListener("change", (event) => {
        const file = event.target.files[0];
        clearImportPreview(false);
        if (!file) return;
        if (dirty) return notify("Lưu các thay đổi đang sửa trước khi nhập chấm công.", true);
        if (!/\.(xlsx|txt)$/i.test(file.name) || file.size > 5 * 1024 * 1024) {
            return notify("Chọn file .xlsx hoặc .txt tối đa 5 MB.", true);
        }
        importFile = file;
        uploadTimesheet();
    });
    find("[data-text-source]")?.addEventListener("change", (event) => {
        uploadTimesheet(event.target.value);
    });

    function uploadTimesheet(source = "") {
        const file = importFile;
        if (!file || busy) return;
        importPreview = null;
        find("[data-timesheet-preview]").hidden = true;
        perform(async () => {
            const body = new FormData();
            const employeeId = entryForm.elements.employee_id.value;
            const version = Number(detail.period.version);
            body.append("timesheet", file);
            body.append("period_id", detail.period.id);
            body.append("employee_id", employeeId);
            body.append("version", String(version));
            body.append("source_employee", source);
            const response = await fetch("/api/admin/payroll", {
                method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": root.dataset.csrf }, body,
            });
            let data;
            try {
                data = await response.json();
            } catch {
                throw new Error("Không đọc được phản hồi. Kiểm tra giới hạn upload của hosting và thử lại.");
            }
            if (!response.ok) throw new Error(data.error || "Không đọc được file chấm công.");
            importPreview = { ...data, employeeId, version };
            const sourceLabel = find("[data-text-source-label]");
            sourceLabel.hidden = !data.sources;
            if (data.sources) {
                const sourceSelect = find("[data-text-source]");
                sourceSelect.innerHTML = '<option value="">Chọn nhân viên trong file</option>' + data.sources.map((item) => `<option value="${escape(item.key)}">${escape(item.code + " — " + item.name)} (${Number(item.count)} lần quẹt)</option>`).join("");
                sourceSelect.value = data.selected_source;
            }
            const preview = find("[data-timesheet-preview]");
            preview.hidden = false;
            preview.innerHTML = `<h3>Xem trước chấm công</h3>
                <p class="hr-muted text-sm">${escape(file.name)} / ${escape(data.sheet)}. ${data.shifts.length} ca hợp lệ. ${data.errors.length} lỗi. Chưa lưu thay đổi.</p>
                ${data.sources ? `<p class="hr-muted text-sm">Chỉ nhập nhân viên được chọn vào hồ sơ đang mở. ${Number(data.ignored)} lần quẹt ngoài kỳ được bỏ qua.</p>` : ""}
                ${data.errors.length ? `<ul class="hr-status" data-error="true">${data.errors.map((error) => `<li>${error.row ? "Dòng " + Number(error.row) + ": " : ""}${escape(error.message)}</li>`).join("")}</ul>` : `<p class="text-sm">Tổng giờ: ${hours(data.calculated.minutes)}. Thực nhận theo cấu hình hiện tại: ${money(data.calculated.net_salary)}.</p>`}
                <div class="hr-table-wrap"><table class="hr-table"><caption class="sr-only">Ca làm sẽ nhập cho nhân viên đang mở</caption><thead><tr><th>Ngày</th><th>Giờ vào</th><th>Giờ ra</th></tr></thead><tbody>${data.shifts.map((shift) => `<tr><td data-label="Ngày">${date(shift.date)}</td><td data-label="Giờ vào">${escape(shift.in)}</td><td data-label="Giờ ra">${escape(shift.out)}</td></tr>`).join("")}</tbody></table></div>
                <p class="hr-muted text-sm">Xác nhận sẽ thay toàn bộ ca làm hiện có của nhân viên trong kỳ này, giữ nguyên cấu hình lương và các khoản cộng/trừ.</p>
                <div class="hr-actions"><button class="hr-button hr-primary" type="button" data-apply-import ${data.errors.length ? "disabled" : ""}>Xác nhận nhập ca</button><button class="hr-button" type="button" data-cancel-import>Hủy nhập</button></div>`;
            if (data.errors.length && (!data.sources || data.selected_source)) notify("File có lỗi. Sửa các dòng được báo rồi chọn lại file.", true);
        });
    }
    createForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !createForm.reportValidity()) return;
        const values = formValues(createForm);
        if (personnel && values.id && !await question(`Lưu thay đổi hồ sơ nhân viên “${values.name}”?`)) return;
        perform(async () => {
            if (!personnel) values.copy_employees = createForm.elements.copy_employees.checked;
            const result = await api(null, { action: personnel ? "employee/save" : "period/create", ...values });
            dirty = false;
            createPanel.hidden = true;
            notify(personnel ? "Đã lưu hồ sơ nhân viên." : "Đã tạo kỳ lương.");
            if (personnel) await loadList();
            else await openPeriod(result.period.id);
        });
    });
    entryForm?.addEventListener("submit", (event) => {
        event.preventDefault();
        if (!entryForm.reportValidity()) return;
        const values = formValues(entryForm);
        if (values.weekend_hours === "") delete values.weekend_hours;
        else values.weekend_hours = Number(values.weekend_hours);
        const employeeId = values.employee_id;
        delete values.employee_id;
        values.prorate = entryForm.elements.prorate.checked;
        ["shifts", "allowances", "deductions"].forEach((kind) => { values[kind] = readRows(kind); });
        perform(() => mutate("entry/save", { employee_id: employeeId, details: values }));
    });
    find("[data-add-employee]")?.addEventListener("submit", (event) => {
        event.preventDefault();
        const id = event.currentTarget.elements.employee_id.value;
        if (!id || dirty) {
            if (dirty) notify("Lưu hoặc đóng phần đang sửa trước khi thêm nhân viên.", true);
            return;
        }
        perform(async () => {
            await mutate("entry/save", { employee_id: id });
            showAddEmployee = false;
            updateAddEmployeeState();
        });
    });
    filter.addEventListener("submit", (event) => {
        event.preventDefault();
        periodPage = 1;
        renamingPeriod = null;
        updateUrl({ q: filter.elements.q.value, status: filter.elements.status?.value || "" });
        renderList();
    });
    root.addEventListener("submit", (event) => {
        const form = event.target.closest("[data-rename-period-form]");
        if (!form) return;
        event.preventDefault();
        const period = periods.find((row) => row.id === form.dataset.periodId);
        perform(async () => {
            await api(null, { action: "period/rename", period_id: period.id, version: Number(period.version), name: form.elements.name.value });
            renamingPeriod = null;
            await loadList();
            notify("Đã đổi tên kỳ lương.");
        });
    });
    root.addEventListener("click", async (event) => {
        if (event.target.closest("[data-action-item]")) closeActions();
        const button = event.target.closest("button");
        if (!button || button.disabled) return;
        if (button.hasAttribute("data-confirm-cancel")) return answer(false);
        if (button.hasAttribute("data-confirm-accept")) return answer(true);
        if (busy || excelImport?.isBusy()) return;
        if (button.hasAttribute("data-period-excel-open")) {
            if (!await mayDiscard()) return;
            dirty = false;
            editor.hidden = true;
            excelImport.open(detail);
            return;
        }
        if (button.hasAttribute("data-attendance-open")) {
            if (!await mayDiscard()) return;
            const employee = detail.entries.find((entry) => entry.employee_id === button.dataset.attendanceOpen);
            if (!employee) return;
            dirty = false;
            editor.hidden = true;
            attendance.open(employee, detail.period);
            return;
        }
        if (button.hasAttribute("data-show-add")) {
            showAddEmployee = !showAddEmployee;
            updateAddEmployeeState();
            if (showAddEmployee) find("[data-add-employee] select").focus();
            return;
        }
        if (button.hasAttribute("data-review-quick-value")) {
            reviewQuick = button.dataset.reviewQuickValue;
            renderLedger();
            find(`[data-review-quick-value="${reviewQuick}"]`).focus();
            return;
        }
        if (button.hasAttribute("data-delete-employee")) {
            const employee = employees.find((row) => row.id === button.dataset.deleteEmployee);
            if (!employee || !await mayDiscard()) return;
            if (await question(`Xóa nhân viên “${employee.name}”? Lịch sử bảng lương vẫn được giữ.`)) {
                perform(async () => {
                    await api(null, { action: "employee/delete", id: employee.id, version: Number(employee.version) });
                    dirty = false;
                    createPanel.hidden = true;
                    createForm.reset();
                    await loadList();
                    notify("Đã xóa nhân viên.");
                });
            }
            return;
        }
        if (button.hasAttribute("data-period-page")) {
            periodPage = Number(button.dataset.periodPage);
            renamingPeriod = null;
            renderList();
            find(`[data-period-page="${periodPage}"][aria-current="page"]`)?.focus();
            return;
        }
        if (button.hasAttribute("data-rename-period")) {
            renamingPeriod = button.dataset.renamePeriod;
            renderList();
            find("[data-rename-period-form] input").focus();
            return;
        }
        if (button.hasAttribute("data-cancel-rename")) {
            renamingPeriod = null;
            renderList();
            return;
        }
        if (button.hasAttribute("data-delete-period")) {
            const period = periods.find((row) => row.id === button.dataset.deletePeriod);
            if (await question(`Xóa kỳ lương “${period.name}” và toàn bộ dòng lương trong kỳ? Hồ sơ nhân viên vẫn được giữ.`)) {
                perform(async () => {
                    await api(null, { action: "period/delete", period_id: period.id, version: Number(period.version) });
                    await loadList();
                    notify("Đã xóa kỳ lương.");
                });
            }
            return;
        }
        if (button.hasAttribute("data-cancel-import")) {
            clearImportPreview();
            return;
        }
        if (button.hasAttribute("data-apply-import")) {
            if (!importPreview || importPreview.errors.length) return;
            if (dirty || importPreview.version !== Number(detail.period.version)) {
                return notify("Dữ liệu đã thay đổi. Lưu phần đang sửa và chọn lại file chấm công.", true);
            }
            const entry = detail.entries.find((row) => row.employee_id === importPreview.employeeId);
            const imported = importPreview;
            if (await question(`Thay toàn bộ ca làm của ${entry.employee_name} bằng ${imported.shifts.length} ca từ file chấm công?`)) {
                perform(() => mutate("entry/save", {
                    employee_id: imported.employeeId,
                    details: { ...entry.details, shifts: imported.shifts },
                }));
            }
            return;
        }
        if (button.hasAttribute("data-new") || button.hasAttribute("data-start-create") || button.hasAttribute("data-edit-employee")) {
            if (!await mayDiscard()) return;
            const employee = employees.find((row) => row.id === button.dataset.editEmployee);
            fill(createForm, employee || {});
            find("[data-create-title]").textContent = employee ? "Sửa hồ sơ nhân viên" : (personnel ? "Thêm nhân viên" : "Tạo kỳ lương");
            createPanel.hidden = false;
            dirty = false;
            createForm.querySelector("input:not([type=hidden])").focus();
        } else if (button.hasAttribute("data-close-create")) {
            if (!await mayDiscard()) return;
            dirty = false;
            createPanel.hidden = personnel;
            if (personnel) find("[data-new]").focus();
            else {
                createForm.reset();
                createForm.elements.name.focus();
            }
        } else if (button.hasAttribute("data-reload")) {
            perform(async () => { await loadList(); notify("Đã tải lại dữ liệu."); });
        } else if (button.hasAttribute("data-open-period")) {
            if (!await mayDiscard()) return;
            perform(() => openPeriod(button.dataset.openPeriod));
        } else if (button.hasAttribute("data-back")) {
            if (!await mayDiscard()) return;
            detail = null;
            dirty = false;
            detailPanel.hidden = true;
            editor.hidden = true;
            find("[data-list-section]").hidden = false;
            createPanel.hidden = false;
            const newButton = find("[data-new]");
            if (newButton) newButton.hidden = false;
            updateUrl({ period: "" });
            perform(loadList);
        } else if (button.hasAttribute("data-edit-entry")) {
            if (await mayDiscard()) editEntry(button.dataset.editEntry);
        } else if (button.hasAttribute("data-close-entry")) {
            if (!await mayDiscard()) return;
            dirty = false;
            editor.hidden = true;
        } else if (button.hasAttribute("data-add-row")) {
            addRow(button.dataset.addRow);
            dirty = true;
        } else if (button.hasAttribute("data-remove-row")) {
            button.closest("[data-row]").remove();
            dirty = true;
        } else if (button.hasAttribute("data-remove-entry")) {
            if (dirty) return notify("Lưu hoặc đóng phần đang sửa trước khi bỏ nhân viên khỏi kỳ.", true);
            if (await question("Bỏ dòng lương này khỏi kỳ? Hồ sơ nhân viên vẫn được giữ.")) {
                perform(() => mutate("entry/remove", { employee_id: button.dataset.removeEntry }));
            }
        }
    });
    root.addEventListener("keydown", (event) => {
        if (!resolveQuestion) return;
        if (event.key === "Escape") { event.preventDefault(); answer(false); }
        if (event.key === "Tab") {
            event.preventDefault();
            const cancel = find("[data-confirm-cancel]");
            const accept = find("[data-confirm-accept]");
            (document.activeElement === cancel ? accept : cancel).focus();
        }
    });
    window.addEventListener("beforeunload", (event) => {
        if (!dirty && !attendance?.isDirty()) return;
        event.preventDefault();
        event.returnValue = "";
    });
    const params = new URLSearchParams(window.location.search);
    filter.elements.q.value = params.get("q") || "";
    if (filter.elements.status) filter.elements.status.value = params.get("status") || "";
    const initialize = () => {
        window.MoPayrollDates.enhance(root);
        return perform(() => !personnel && params.get("period") ? openPeriod(params.get("period")) : loadList());
    };
    if (document.readyState !== "complete") {
        document.addEventListener("DOMContentLoaded", initialize, { once: true });
    } else {
        initialize();
    }
})();
