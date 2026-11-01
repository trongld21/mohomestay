(() => {
    "use strict";
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
    const icon = (name) => `<svg class="admin-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-${name}"></use></svg>`;
    const hours = (minutes) => (minutes / 60).toFixed(2);

    function validate(shifts, period) {
        const rows = shifts.map((shift) => {
            let message = "";
            const day = Date.parse(shift.date + "T00:00:00Z");
            const time = (value) => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value) ? Number(value.slice(0, 2)) * 60 + Number(value.slice(3)) : NaN;
            const from = time(shift.in);
            const to = time(shift.out);
            const duration = (to < from ? to + 1440 : to) - from;
            if (!Number.isFinite(day) || new Date(day).toISOString().slice(0, 10) !== shift.date || shift.date < period.start_date || shift.date > period.end_date) message = "Ngày phải nằm trong kỳ lương.";
            else if (!Number.isFinite(duration) || duration <= 0) message = "Nhập đủ giờ vào và giờ ra khác nhau.";
            else if (!Number.isInteger(shift.break_minutes) || shift.break_minutes < 0 || shift.break_minutes >= duration) message = "Phút nghỉ phải nhỏ hơn thời lượng ca.";
            else if (shift.hourly_rate !== undefined && (!Number.isInteger(shift.hourly_rate) || shift.hourly_rate < 0 || shift.hourly_rate > 1000000000)) message = "Đơn giá phải là số nguyên từ 0 đến 1 tỷ đồng/giờ.";
            return { message, minutes: message ? 0 : duration - shift.break_minutes, from: day / 60000 + from, to: day / 60000 + from + duration };
        });
        for (let left = 0; left < rows.length; left++) {
            for (let right = left + 1; right < rows.length; right++) {
                const a = rows[left];
                const b = rows[right];
                if (Number.isFinite(a.from) && Number.isFinite(b.from) && a.from < b.to && a.to > b.from) {
                    a.message = b.message = "Ca làm bị trùng thời gian.";
                }
            }
        }
        return rows;
    }

    function create(root, options) {
        const dialog = document.createElement("dialog");
        dialog.className = "hr-attendance-dialog";
        dialog.setAttribute("aria-labelledby", "hr-attendance-title");
        dialog.innerHTML = `<form class="hr-attendance-form" novalidate>
            <header class="hr-attendance-header"><h2 id="hr-attendance-title">Chi tiết chấm công</h2><div class="hr-actions"><strong class="hr-attendance-person" data-person></strong><button class="hr-button hr-icon-button" type="button" data-close-attendance aria-label="Đóng chi tiết chấm công">${icon("x")}</button></div></header>
            <div class="hr-attendance-stats"><div><span>Số ca</span><strong data-count></strong></div><div><span>Cần sửa</span><strong data-invalid></strong></div><div><span>Giờ thực làm</span><strong data-hours></strong></div></div>
            <p class="hr-muted px-5 py-3 text-xs" data-attendance-note>Giờ ra trước giờ vào là ca qua đêm.</p><span class="sr-only" id="hr-shift-drag-help">Kéo tay nắm trong cột TT hoặc dùng phím mũi tên lên, xuống để đổi thứ tự ca. Home và End đưa ca về đầu hoặc cuối.</span><span class="sr-only" data-order-status aria-live="polite"></span>
            <div class="hr-attendance-scroll"><table class="hr-table hr-attendance-table"><caption class="sr-only">Danh sách ca làm</caption><thead><tr><th>TT</th><th>Ngày</th><th>Giờ vào</th><th></th><th>Giờ ra</th><th>Số giờ</th><th>Thao tác</th></tr></thead><tbody data-shifts></tbody></table><p class="hr-state" data-empty>Chưa có ca làm. Chọn Thêm ca để bắt đầu.</p></div>
            <p class="hr-status mx-5 my-2" data-error="true" data-attendance-error hidden role="alert"></p>
            <footer class="hr-attendance-footer"><button class="hr-button" type="button" data-new-shift>${icon("plus")}Thêm ca</button><div class="hr-actions"><span class="hr-muted">Tổng giờ <strong class="hr-income" data-total></strong></span><button class="hr-button hr-positive" type="submit" data-save-attendance>${icon("device-floppy")}Lưu thay đổi</button></div></footer>
        </form><div class="hr-attendance-confirm" data-attendance-confirm hidden role="alertdialog" aria-modal="true" aria-labelledby="hr-attendance-confirm-title"><div class="hr-surface"><h3 id="hr-attendance-confirm-title">Xác nhận thao tác</h3><p class="mt-3" data-confirm-message></p><div class="hr-actions mt-4 justify-end"><button class="hr-button" type="button" data-no>Hủy</button><button class="hr-button hr-positive" type="button" data-yes>Xác nhận</button></div></div></div>`;
        root.append(dialog);
        const find = (selector) => dialog.querySelector(selector);
        const form = find("form");
        const body = find("[data-shifts]");
        let entry;
        let period;
        let changed = false;
        let busy = false;
        let previousFocus;
        let confirmAction = null;
        let confirmFocus;
        let drag = null;
        const rowAnimations = new Map();
        const read = () => [...body.children].map((row) => {
            const shift = { break_minutes: Number(row.dataset.breakMinutes || 0) };
            row.querySelectorAll("[data-shift-key]").forEach((input) => {
                if (input.dataset.shiftKey === "hourly_rate") {
                    if (input.validity.badInput) shift.hourly_rate = NaN;
                    else if (input.value !== "") shift.hourly_rate = Number(input.value);
                } else shift[input.dataset.shiftKey] = input.value;
            });
            return shift;
        });

        function restoreFocus() {
            const target = previousFocus?.isConnected ? previousFocus : root.querySelector(`[data-attendance-open="${CSS.escape(entry.employee_id)}"]`);
            target?.focus();
        }

        function refresh() {
            const values = read();
            const states = validate(values, period);
            let total = 0;
            [...body.children].forEach((row, index) => {
                const state = states[index];
                row.querySelector("[data-index]").textContent = index + 1;
                const handle = row.querySelector("[data-drag-shift]");
                handle.setAttribute("aria-label", "Di chuyển ca " + (index + 1));
                handle.disabled = busy || values.length < 2;
                const insert = row.querySelector("[data-insert-shift]");
                insert.hidden = index === values.length - 1;
                insert.disabled = busy || values.length >= 200;
                const insertLabel = `Chèn ca giữa ca ${index + 1} và ca ${index + 2}`;
                insert.setAttribute("aria-label", insertLabel);
                insert.setAttribute("title", insertLabel);
                row.querySelector("[data-row-hours]").textContent = state.message ? "—" : hours(state.minutes) + "h";
                row.querySelector("[data-row-error]").textContent = state.message;
                row.dataset.invalid = String(Boolean(state.message));
                if (!state.message) total += state.minutes;
            });
            find("[data-count]").textContent = values.length;
            find("[data-invalid]").textContent = states.filter((state) => state.message).length;
            find("[data-hours]").textContent = hours(total) + "h";
            find("[data-total]").textContent = hours(total);
            find("[data-empty]").hidden = values.length > 0;
            find("[data-save-attendance]").disabled = busy || states.some((state) => state.message);
            find("[data-new-shift]").disabled = busy || values.length >= 200;
        }

        function append(shift = { date: period.start_date, in: "", out: "", break_minutes: 0 }, after = null) {
            const row = document.createElement("tr");
            row.dataset.breakMinutes = Number(shift.break_minutes || 0);
            row.innerHTML = `<td data-label="TT"><div class="hr-shift-order"><button class="hr-button hr-drag-shift" type="button" data-drag-shift aria-describedby="hr-shift-drag-help" title="Kéo hoặc dùng phím ↑/↓ để đổi thứ tự">${icon("grip-vertical")}</button><strong data-index></strong></div></td>
                <td data-label="Ngày"><div><label class="hr-label"><span class="sr-only">Ngày</span><input class="hr-field" type="date" data-shift-key="date" min="${escape(period.start_date)}" max="${escape(period.end_date)}" value="${escape(shift.date)}" required></label><p class="hr-deduction mt-2 text-xs" data-row-error></p></div></td>
                <td data-label="Giờ vào"><label class="hr-label"><span class="sr-only">Giờ vào</span><input class="hr-field" type="text" placeholder="HH:mm" maxlength="5" data-shift-key="in" value="${escape(shift.in)}" required></label></td>
                <td data-label="Đổi giờ"><button class="hr-button hr-icon-button hr-info hr-swap-time" type="button" data-swap aria-label="Đổi giờ vào và giờ ra" title="Đổi giờ vào và giờ ra">${icon("arrows-exchange")}</button></td>
                <td data-label="Giờ ra"><label class="hr-label"><span class="sr-only">Giờ ra</span><input class="hr-field" type="text" placeholder="HH:mm" maxlength="5" data-shift-key="out" value="${escape(shift.out)}" required></label></td>
                <td data-label="Số giờ"><div class="grid gap-2"><strong class="hr-income tabular-nums" data-row-hours></strong>${entry.details.salary_type === "hourly" ? `<label class="hr-shift-rate"><span class="sr-only">Đơn giá giờ của ca</span><input class="hr-field" type="number" min="0" max="1000000000" step="1" data-shift-key="hourly_rate" value="${shift.hourly_rate === undefined || shift.hourly_rate === null ? "" : Number(shift.hourly_rate)}" placeholder="${Number(entry.details.hourly_rate)}"><span class="hr-muted text-xs" aria-hidden="true">đ/h</span></label>` : ""}</div></td>
                <td data-label="Thao tác"><button class="hr-button hr-icon-button hr-danger" type="button" data-delete-shift aria-label="Xóa ca" title="Xóa ca">${icon("trash")}</button><button class="hr-button hr-insert-shift" type="button" data-insert-shift>${icon("plus")}</button></td>`;
            if (after?.parentElement === body) after.after(row);
            else body.append(row);
            window.MoPayrollDates.enhance(row);
            refresh();
            return row;
        }

        function ask(message, action) {
            confirmFocus = document.activeElement;
            confirmAction = action;
            find("[data-confirm-message]").textContent = message;
            find("[data-attendance-confirm]").hidden = false;
            find("form").inert = true;
            find("[data-no]").focus();
        }

        function animateOrder(move) {
            const positions = new Map([...body.children].map((row) => [row, row.getBoundingClientRect().top]));
            rowAnimations.forEach((animation) => animation.cancel());
            rowAnimations.clear();
            move();
            if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
            positions.forEach((top, row) => {
                const distance = top - row.getBoundingClientRect().top;
                if (Math.abs(distance) < 1) return;
                const animation = row.animate([{ transform: `translateY(${distance}px)` }, { transform: "translateY(0)" }], {
                    duration: 180, easing: "cubic-bezier(.2,.7,.2,1)",
                });
                rowAnimations.set(row, animation);
                animation.onfinish = () => { if (rowAnimations.get(row) === animation) rowAnimations.delete(row); };
            });
        }

        function reorderAt(y) {
            if (!drag?.active) return;
            const others = [...body.children].filter((row) => row !== drag.row);
            const target = others.find((row) => {
                const rect = row.getBoundingClientRect();
                const transform = getComputedStyle(row).transform;
                const offset = transform === "none" ? 0 : new DOMMatrixReadOnly(transform).m42;
                return y < rect.top - offset + rect.height / 2;
            }) || null;
            if (drag.row.nextElementSibling === target) return;
            animateOrder(() => body.insertBefore(drag.row, target));
            // Moving the handle can release pointer capture in some browsers.
            try { drag.handle.setPointerCapture(drag.pointerId); } catch { /* The pointer may have just ended. */ }
            refresh();
        }

        function autoScrollDrag() {
            if (!drag?.active) return;
            const scroll = find(".hr-attendance-scroll");
            const rect = scroll.getBoundingClientRect();
            const edge = 44;
            const delta = drag.y < rect.top + edge ? -Math.min(18, (rect.top + edge - drag.y) / 3)
                : drag.y > rect.bottom - edge ? Math.min(18, (drag.y - rect.bottom + edge) / 3) : 0;
            if (delta) scroll.scrollTop += delta;
            reorderAt(drag.y);
            drag.frame = requestAnimationFrame(autoScrollDrag);
        }

        function finishDrag(cancel = false) {
            if (!drag) return;
            const current = drag;
            drag = null;
            cancelAnimationFrame(current.frame);
            current.row.classList.remove("is-dragging");
            dialog.classList.remove("is-reordering");
            try { current.handle.releasePointerCapture(current.pointerId); } catch { /* Capture may already have ended. */ }
            if (cancel) current.original.forEach((row) => body.append(row));
            else if (current.original.some((row, index) => body.children[index] !== row)) changed = true;
            refresh();
            if (current.active) {
                current.handle.focus({ preventScroll: true });
                find("[data-order-status]").textContent = cancel ? "Đã hủy đổi thứ tự ca." : "Ca được chuyển đến vị trí " + ([...body.children].indexOf(current.row) + 1) + ". Bấm Lưu thay đổi để ghi lại.";
            }
        }

        dialog.addEventListener("pointerdown", (event) => {
            const handle = event.target.closest("[data-drag-shift]");
            if (!handle || handle.disabled || busy || confirmAction || !event.isPrimary || event.button !== 0) return;
            handle.focus({ preventScroll: true });
            drag = { handle, row: handle.closest("tr"), pointerId: event.pointerId, startY: event.clientY, y: event.clientY,
                active: false, frame: 0, original: [...body.children] };
            handle.setPointerCapture(event.pointerId);
            event.preventDefault();
        });
        dialog.addEventListener("pointermove", (event) => {
            if (!drag || drag.pointerId !== event.pointerId) return;
            drag.y = event.clientY;
            if (!drag.active && Math.abs(event.clientY - drag.startY) >= 5) {
                drag.active = true;
                drag.row.classList.add("is-dragging");
                dialog.classList.add("is-reordering");
                drag.frame = requestAnimationFrame(autoScrollDrag);
            }
            if (drag.active) {
                event.preventDefault();
                reorderAt(event.clientY);
            }
        });
        dialog.addEventListener("pointerup", (event) => {
            if (drag?.pointerId === event.pointerId) finishDrag();
        });
        dialog.addEventListener("pointercancel", (event) => {
            if (drag?.pointerId === event.pointerId) finishDrag(true);
        });
        body.addEventListener("keydown", (event) => {
            const handle = event.target.closest("[data-drag-shift]");
            if (!handle || handle.disabled || busy || confirmAction || !["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) return;
            event.preventDefault();
            event.stopPropagation();
            const row = handle.closest("tr");
            const rows = [...body.children];
            const index = rows.indexOf(row);
            const target = event.key === "Home" ? 0 : event.key === "End" ? rows.length - 1
                : Math.max(0, Math.min(rows.length - 1, index + (event.key === "ArrowUp" ? -1 : 1)));
            if (target === index) return;
            animateOrder(() => body.insertBefore(row, target < index ? rows[target] : rows[target].nextElementSibling));
            changed = true;
            refresh();
            handle.focus({ preventScroll: true });
            row.scrollIntoView({ block: "nearest" });
            find("[data-order-status]").textContent = "Ca được chuyển đến vị trí " + (target + 1) + ". Bấm Lưu thay đổi để ghi lại.";
        });

        function answer(yes) {
            const action = confirmAction;
            confirmAction = null;
            find("[data-attendance-confirm]").hidden = true;
            form.inert = false;
            confirmFocus?.focus();
            if (yes) action?.();
        }

        function close() {
            if (busy) return;
            const finish = () => { changed = false; dialog.close(); restoreFocus(); };
            if (changed) ask("Bỏ các thay đổi chấm công chưa lưu?", finish);
            else finish();
        }

        async function save() {
            busy = true;
            dialog.setAttribute("aria-busy", "true");
            const controls = [...dialog.querySelectorAll("input, select, button")].filter((input) => !input.disabled);
            controls.forEach((input) => { input.disabled = true; });
            find("[data-attendance-error]").hidden = true;
            try {
                await options.onSave({ period_id: period.id, version: Number(period.version), employee_id: entry.employee_id, details: { ...entry.details, shifts: read() } });
                changed = false;
                dialog.close();
                restoreFocus();
            } catch (error) {
                find("[data-attendance-error]").textContent = error.message;
                find("[data-attendance-error]").hidden = false;
            } finally {
                busy = false;
                dialog.removeAttribute("aria-busy");
                controls.forEach((input) => { input.disabled = false; });
                refresh();
            }
        }

        form.addEventListener("input", () => { changed = true; refresh(); });
        form.addEventListener("focusout", (event) => {
            const input = event.target;
            if (!["in", "out"].includes(input.dataset.shiftKey) || !/^\d:\d{2}$/.test(input.value)) return;
            input.value = "0" + input.value;
            changed = true;
            refresh();
        });
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (busy || validate(read(), period).some((state) => state.message)) return;
            ask(`Lưu thay đổi chấm công của “${entry.employee_name}” và tính lại lương?`, save);
        });
        dialog.addEventListener("click", (event) => {
            event.stopPropagation();
            const button = event.target.closest("button");
            if (!button || button.disabled) return;
            if (button.hasAttribute("data-no")) return answer(false);
            if (button.hasAttribute("data-yes")) return answer(true);
            if (busy || confirmAction) return;
            if (button.hasAttribute("data-close-attendance")) return close();
            if (button.hasAttribute("data-new-shift")) {
                const row = append();
                changed = true;
                row.scrollIntoView({ block: "nearest" });
                row.querySelector(".manual-date-trigger")?.focus();
            }
            if (button.hasAttribute("data-insert-shift") && body.children.length < 200) {
                const previous = button.closest("tr");
                const row = append({ date: previous.querySelector('[data-shift-key="date"]').value || period.start_date, in: "", out: "", break_minutes: 0 }, previous);
                changed = true;
                row.scrollIntoView({ block: "nearest" });
                row.querySelector(".manual-date-trigger")?.focus();
            }
            if (button.hasAttribute("data-delete-shift")) {
                const row = button.closest("tr");
                ask("Xóa ca làm này? Thay đổi chỉ được ghi khi bấm Lưu thay đổi.", () => { row.remove(); changed = true; refresh(); find("[data-new-shift]").focus(); });
            }
            if (button.hasAttribute("data-swap")) {
                const row = button.closest("tr");
                const from = row.querySelector('[data-shift-key="in"]');
                const to = row.querySelector('[data-shift-key="out"]');
                [from.value, to.value] = [to.value, from.value];
                button.classList.add("is-swapping");
                setTimeout(() => button.classList.remove("is-swapping"), 220);
                changed = true;
                refresh();
            }
        });
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            if (drag) return finishDrag(true);
            if (confirmAction) answer(false);
            else close();
        });
        dialog.addEventListener("keydown", (event) => {
            if (!confirmAction || event.key !== "Tab") return;
            event.preventDefault();
            (document.activeElement === find("[data-no]") ? find("[data-yes]") : find("[data-no]")).focus();
        });

        return {
            open(employee, currentPeriod) {
                finishDrag(true);
                entry = structuredClone(employee);
                period = structuredClone(currentPeriod);
                previousFocus = document.activeElement;
                changed = false;
                body.replaceChildren();
                find("[data-person]").textContent = entry.employee_name + " — " + entry.employee_code;
                find("[data-attendance-error]").hidden = true;
                find("[data-attendance-note]").textContent = "Giờ ra trước giờ vào là ca qua đêm.";
                entry.details.shifts.forEach((shift) => append(shift));
                refresh();
                dialog.showModal();
                find("[data-close-attendance]").focus();
            },
            isDirty: () => dialog.open && changed,
        };
    }
    window.MoPayrollAttendance = { create, validate };
})();
