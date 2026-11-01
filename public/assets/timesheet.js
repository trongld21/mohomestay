(() => {
    "use strict";
    const root = document.querySelector("[data-timesheet]");
    if (!root) return;
    const form = root.querySelector("[data-timesheet-form]");
    const button = root.querySelector("[data-calculate]");
    const results = root.querySelector("[data-timesheet-results]");
    const empty = root.querySelector("[data-timesheet-empty]");
    const escape = (value) =>
        String(value ?? "").replace(
            /[&<>"']/g,
            (char) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                })[char],
        );
    const hours = (minutes) =>
        new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 }).format(
            minutes / 60,
        );
    const date = (value) => value.split("-").reverse().join("/");
    let busy = false;
    let employeeOptions = null;

    function update() {
        button.disabled =
            busy ||
            !form.elements.timesheet.files.length ||
            !form.elements.start_date.value ||
            !form.elements.end_date.value ||
            (form.elements.source_format.value === "excel" &&
                !form.elements.employee_id.value);
    }

    function invalidate() {
        results.hidden = true;
        results.replaceChildren();
        empty.hidden = false;
        update();
    }

    function render(data) {
        const rows = data.employees;
        const errorCount =
            data.errors.length +
            rows.reduce((sum, row) => sum + row.errors.length, 0);
        const minutes = rows.reduce((sum, row) => sum + row.minutes, 0);
        results.innerHTML = `<header class="hr-toolbar"><div><h2>Kết quả chấm công</h2><p class="hr-muted mt-1 text-sm">${date(data.start_date)} – ${date(data.end_date)} · ${rows.length} nhân viên · ${hours(minutes)} giờ hợp lệ · ${errorCount} lỗi</p></div></header>
            ${data.errors.length ? `<ul class="hr-status" data-error="true">${data.errors.map((error) => `<li>Dòng ${Number(error.row)}: ${escape(error.message)}</li>`).join("")}</ul>` : ""}
            <div class="hr-surface p-0">${rows
                .map(
                    (
                        row,
                    ) => `<details class="border-b border-[var(--hr-line)] last:border-b-0">
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 p-5 hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2">
                    <span><strong>${escape(row.name || row.code)}</strong><span class="hr-muted mt-1 block text-sm">${escape(row.code)} · ${row.shifts.length} ca · ${row.ignored} lần quẹt ngoài khoảng ngày</span></span>
                    <span class="hr-actions"><span class="hr-tag" data-state="${row.errors.length ? "draft" : "active"}">${row.errors.length ? row.errors.length + " lỗi" : "Hợp lệ"}</span><strong class="hr-income tabular-nums">${hours(row.minutes)} giờ</strong></span>
                </summary>
                <div class="grid gap-3 px-5 pb-5">
                    <p class="hr-muted text-sm">${row.match === "matched" ? "Đã khớp hồ sơ nhân sự." : row.match === "ambiguous" ? "Có nhiều hồ sơ trùng mã hoặc tên. Kiểm tra nhân sự trước khi đưa vào bảng lương." : "Chưa khớp hồ sơ nhân sự. Kiểm tra mã hoặc tên trước khi đưa vào bảng lương."} Giờ T7, CN: ${hours(row.weekend_minutes)}.</p>
                    ${row.errors.length ? `<ul class="hr-status" data-error="true">${row.errors.map((error) => `<li>${error.row ? "Dòng " + Number(error.row) + ": " : ""}${escape(error.message)}</li>`).join("")}</ul>` : ""}
                    <div class="hr-table-wrap"><table class="hr-table"><caption class="sr-only">Ca làm của ${escape(row.name)}</caption><thead><tr><th>Ngày</th><th>Giờ vào</th><th>Giờ ra</th></tr></thead><tbody>${row.shifts.map((shift) => `<tr><td data-label="Ngày">${date(shift.date)}</td><td data-label="Giờ vào">${escape(shift.in)}</td><td data-label="Giờ ra">${escape(shift.out)}</td></tr>`).join("")}</tbody></table></div>
                </div></details>`,
                )
                .join("")}</div>`;
        results.hidden = false;
        empty.hidden = true;
    }

    form.addEventListener("input", invalidate);
    form.elements.timesheet.addEventListener("change", () => {
        root.querySelector("[data-file-name]").textContent =
            form.elements.timesheet.files[0]?.name || "Chọn file TXT hoặc CSV";
        invalidate();
    });
    root.addEventListener("click", async (event) => {
        const modeButton = event.target.closest("[data-import-mode]");
        if (modeButton && !busy) {
            const mode = modeButton.dataset.importMode;
            const excel = mode === "excel";
            form.elements.source_format.value = mode;
            form.elements.timesheet.value = "";
            form.elements.timesheet.accept = excel ? ".xlsx" : ".txt,.csv";
            root.querySelector("[data-file-name]").textContent = excel
                ? "Chọn file Excel của nhân viên"
                : "Chọn file TXT hoặc CSV";
            root.querySelector("[data-excel-employee]").hidden = !excel;
            form.elements.employee_id.disabled = !excel || !employeeOptions;
            form.elements.employee_id.required = excel;
            root.querySelectorAll("[data-import-mode]").forEach((item) =>
                item.setAttribute("aria-pressed", String(item === modeButton)),
            );
            root.querySelector("[data-import-description]").textContent = excel
                ? "Chọn nhân viên, bảng Excel chấm công và khoảng ngày cần xử lý."
                : "Chọn file từ máy chấm công và khoảng ngày cần xử lý.";
            root.querySelector("[data-format-hint]").textContent = excel
                ? "Excel .xlsx tối đa 5 MB, gồm một sheet có cột Ngày làm, Check-in, Check-out. Không nhập số tiền hay công thức trong file."
                : "TXT/CSV UTF-8 từ máy chấm công, tối đa 5 MB. Mã nhân viên ở cột 3, tên ở cột 4, thời điểm quẹt ở cột 7.";
            invalidate();
            if (excel && !employeeOptions) {
                try {
                    const response = await fetch(
                        "/api/admin/payroll?resource=employees",
                        { credentials: "same-origin" },
                    );
                    const data = await response.json();
                    if (!response.ok)
                        throw new Error(
                            data.error || "Không tải được nhân viên.",
                        );
                    employeeOptions = data.employees;
                    form.elements.employee_id.innerHTML =
                        '<option value="">Chọn nhân viên</option>' +
                        employeeOptions
                            .map(
                                (employee) =>
                                    `<option value="${escape(employee.id)}">${escape(employee.code + " — " + employee.name)}</option>`,
                            )
                            .join("");
                    if (form.elements.source_format.value === "excel")
                        form.elements.employee_id.disabled = false;
                } catch (error) {
                    window.LangUI.toast({
                        type: "error",
                        message: error.message,
                        persistent: true,
                    });
                }
                update();
            }
            return;
        }
        const preset = event.target.closest("[data-range-preset]");
        if (!preset || busy) return;
        const parts = new Intl.DateTimeFormat("en-CA", {
            year: "numeric",
            month: "2-digit",
            timeZone: "Asia/Ho_Chi_Minh",
        }).formatToParts(new Date());
        const year = Number(parts.find((part) => part.type === "year").value);
        const monthNumber = Number(
            parts.find((part) => part.type === "month").value,
        );
        const prefix = year + "-" + String(monthNumber).padStart(2, "0") + "-";
        form.elements.start_date.value =
            prefix + (preset.dataset.rangePreset === "first" ? "01" : "16");
        form.elements.end_date.value =
            prefix +
            (preset.dataset.rangePreset === "first"
                ? "15"
                : new Date(Date.UTC(year, monthNumber, 0)).getUTCDate());
        form.elements.start_date.dispatchEvent(
            new Event("input", { bubbles: true }),
        );
        form.elements.end_date.dispatchEvent(
            new Event("input", { bubbles: true }),
        );
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        const file = form.elements.timesheet.files[0];
        const excel = form.elements.source_format.value === "excel";
        if (
            !file ||
            !(excel ? /\.xlsx$/i : /\.(txt|csv)$/i).test(file.name) ||
            file.size > 5 * 1024 * 1024
        ) {
            window.LangUI.toast({
                type: "error",
                message: excel
                    ? "Chọn file Excel .xlsx tối đa 5 MB."
                    : "Chọn file TXT hoặc CSV tối đa 5 MB.",
                persistent: true,
            });
            return;
        }
        const body = new FormData(form);
        body.append("action", "timesheet/preview");
        busy = true;
        update();
        root.setAttribute("aria-busy", "true");
        const controls = [
            ...form.querySelectorAll("input, button, select"),
        ].filter((control) => !control.disabled);
        controls.forEach((control) => {
            control.disabled = true;
        });
        try {
            const response = await fetch("/api/admin/payroll", {
                method: "POST",
                credentials: "same-origin",
                headers: { "X-CSRF-Token": root.dataset.csrf },
                body,
            });
            let data;
            try {
                data = await response.json();
            } catch {
                throw new Error(
                    "Không đọc được phản hồi. Kiểm tra kết nối và giới hạn upload của máy chủ.",
                );
            }
            if (!response.ok)
                throw new Error(data.error || "Không đọc được file chấm công.");
            render(data);
            window.LangUI.toast({
                type: "success",
                message:
                    "Đã tính giờ làm. Mở từng nhân viên để kiểm tra ca và lỗi.",
            });
        } catch (error) {
            window.LangUI.toast({
                type: "error",
                message: error.message,
                persistent: true,
            });
        } finally {
            busy = false;
            controls.forEach((control) => {
                control.disabled = false;
            });
            root.removeAttribute("aria-busy");
            update();
        }
    });
    function init() {
        window.MoPayrollDates.enhance(root);
        update();
    }
    if (document.readyState === "complete") init();
    else document.addEventListener("DOMContentLoaded", init, { once: true });
})();
