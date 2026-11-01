(() => {
    "use strict";
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
    const money = (value) =>
        new Intl.NumberFormat("vi-VN").format(value) + " đ";
    const hours = (minutes) =>
        new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 }).format(
            minutes / 60,
        );
    const date = (value) => value.split("-").reverse().join("/");

    function create(root, options) {
        const panel = document.createElement("section");
        panel.className = "hr-surface grid min-w-0 gap-4";
        panel.hidden = true;
        panel.dataset.periodExcelPanel = "";
        panel.innerHTML = `<header class="hr-toolbar"><div><h2>Upload Excel nhân viên</h2></div><button class="hr-button hr-icon-button" type="button" data-excel-close aria-label="Đóng phần upload Excel" title="Đóng"><svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
            <form class="grid items-start gap-4 sm:grid-cols-2" data-period-excel-form>
                <label class="hr-label">Nhân viên nhận chấm công<select class="hr-field" name="employee_id" required></select></label>
                <label class="hr-label">File Excel tự chấm công (.xlsx)<input class="hr-field" type="file" name="timesheet" accept=".xlsx" required></label>
            </form>
            <p class="hr-status" data-error="true" data-excel-error role="alert" hidden></p>
            <div class="grid min-w-0 gap-4" data-period-excel-preview hidden></div>`;
        root.querySelector(".hr-ledger-overview").after(panel);
        const form = panel.querySelector("form");
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
        const preview = panel.querySelector("[data-period-excel-preview]");
        const error = panel.querySelector("[data-excel-error]");
        let snapshot;
        let imported = null;
        let sequence = 0;
        let busy = false;

        function clear() {
            imported = null;
            preview.hidden = true;
            preview.replaceChildren();
            error.hidden = true;
        }

        function controls(disabled) {
            form.querySelectorAll("input, select").forEach((input) => {
                input.disabled = disabled || !snapshot?.entries.length;
            });
            preview.querySelectorAll("button").forEach((button) => {
                button.disabled = disabled;
            });
        }

        function reset() {
            sequence++;
            clear();
            panel.hidden = true;
            form.reset();
        }

        form.elements.employee_id.addEventListener("change", () => {
            sequence++;
            clear();
            form.elements.timesheet.value = "";
        });
        form.elements.timesheet.addEventListener("change", async () => {
            clear();
            const file = form.elements.timesheet.files[0];
            const employeeId = form.elements.employee_id.value;
            if (!file || busy) return;
            if (!employeeId) {
                error.textContent = "Chọn nhân viên trước khi tải file Excel.";
                error.hidden = false;
                form.elements.timesheet.value = "";
                form.elements.employee_id.focus();
                return;
            }
            if (!/\.xlsx$/i.test(file.name) || file.size > 5 * 1024 * 1024) {
                error.textContent = "Chọn file .xlsx tối đa 5 MB.";
                error.hidden = false;
                return;
            }
            const request = ++sequence;
            busy = true;
            controls(true);
            panel.setAttribute("aria-busy", "true");
            const body = new FormData();
            body.append("period_id", snapshot.period.id);
            body.append("version", String(snapshot.period.version));
            body.append("employee_id", employeeId);
            body.append("timesheet", file);
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
                if (request !== sequence) return;
                if (!response.ok)
                    throw new Error(data.error || "Không đọc được file Excel.");
                const employee = snapshot.entries.find(
                    (entry) => entry.employee_id === employeeId,
                );
                imported = { data, employee, period: snapshot.period };
                preview.innerHTML = `<h3>Xem trước: ${escape(employee.employee_name)}</h3><p class="hr-muted text-sm">${escape(file.name)} · ${data.shifts.length} ca hợp lệ · ${data.errors.length} lỗi. Chưa lưu vào bảng lương.</p>
                    ${data.errors.length ? `<ul class="hr-status" data-error="true">${data.errors.map((item) => `<li>${item.row ? "Dòng " + Number(item.row) + ": " : ""}${escape(item.message)}</li>`).join("")}</ul>` : `<div class="hr-actions"><strong class="hr-income">${hours(data.calculated.minutes)} giờ · ${money(data.calculated.net_salary)}</strong><span class="hr-muted text-sm">Thực nhận theo cấu hình hiện tại</span></div>`}
                    <div class="hr-table-wrap"><table class="hr-table"><caption class="sr-only">Ca làm từ file Excel sẽ nhập</caption><thead><tr><th>Ngày</th><th>Giờ vào</th><th>Giờ ra</th></tr></thead><tbody>${data.shifts.map((shift) => `<tr><td data-label="Ngày">${date(shift.date)}</td><td data-label="Giờ vào">${escape(shift.in)}</td><td data-label="Giờ ra">${escape(shift.out)}</td></tr>`).join("")}</tbody></table></div>
                    ${data.errors.length ? "" : '<button class="hr-button hr-positive justify-self-start" type="button" data-period-excel-apply>Xác nhận nhập vào kỳ lương</button>'}`;
                preview.hidden = false;
            } catch (failure) {
                if (request !== sequence) return;
                error.textContent = failure.message;
                error.hidden = false;
                options.notify(failure.message, true);
            } finally {
                busy = false;
                panel.removeAttribute("aria-busy");
                controls(false);
            }
        });
        panel.addEventListener("click", async (event) => {
            const button = event.target.closest("button");
            if (!button) return;
            event.stopPropagation();
            if (button.hasAttribute("data-excel-close")) {
                if (busy) return;
                reset();
                root.querySelector("[data-period-actions]").focus();
            }
            if (
                !button.hasAttribute("data-period-excel-apply") ||
                !imported ||
                busy
            )
                return;
            const pending = imported;
            if (pending.data.errors.length) return;
            const current = options.getDetail();
            if (
                current.period.id !== pending.period.id ||
                Number(current.period.version) !==
                    Number(pending.period.version)
            ) {
                clear();
                error.textContent =
                    "Kỳ lương đã thay đổi. Mở lại Upload Excel nhân viên và chọn lại file.";
                error.hidden = false;
                return;
            }
            busy = true;
            controls(true);
            try {
                const confirmed = await options.askConfirmation(
                    `Thay ${pending.employee.details.shifts.length} ca hiện có của “${pending.employee.employee_name}” bằng ${pending.data.shifts.length} ca trong file Excel?`,
                );
                if (!confirmed) return;
                await options.onSave({
                    period_id: pending.period.id,
                    version: Number(pending.period.version),
                    employee_id: pending.employee.employee_id,
                    details: {
                        ...pending.employee.details,
                        shifts: pending.data.shifts,
                    },
                });
                reset();
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
                options.notify(failure.message, true);
            } finally {
                busy = false;
                controls(false);
                if (!panel.hidden)
                    preview.querySelector("[data-period-excel-apply]")?.focus();
            }
        });

        return {
            open(detail) {
                if (busy) return;
                reset();
                snapshot = structuredClone(detail);
                form.elements.employee_id.innerHTML =
                    '<option value="">Chọn nhân viên trong kỳ</option>' +
                    snapshot.entries
                        .map(
                            (entry) =>
                                `<option value="${escape(entry.employee_id)}">${escape(entry.employee_name + " — " + entry.employee_code)}</option>`,
                        )
                        .join("");
                if (snapshot.entries.length === 1)
                    form.elements.employee_id.value =
                        snapshot.entries[0].employee_id;
                controls(false);
                panel.hidden = false;
                if (!snapshot.entries.length) {
                    error.textContent =
                        "Thêm nhân viên vào kỳ trước khi upload Excel.";
                    error.hidden = false;
                }
                panel.scrollIntoView({ block: "nearest" });
                form.elements.employee_id.focus();
            },
            reset,
            isBusy: () => busy,
        };
    }
    window.MoPayrollExcelImport = { create };
})();
