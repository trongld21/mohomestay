(() => {
    "use strict";

    let nextId = 0;
    const icon = (name) => `<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-${name}"></use></svg>`;

    function enhance(root) {
        root.querySelectorAll('input[type="date"]:not([data-payroll-date])').forEach((input) => {
            if (input.dataset.payrollDate) return;
            input.dataset.payrollDate = "true";
            const range = input.closest("[data-date-range]");
            const endInput = range?.querySelector('[name="end_date"]');
            const label = input.closest("label");
            const title = range ? "Khoảng ngày" : label?.firstChild?.textContent?.trim() || "Ngày";
            if (!range) input.setAttribute("aria-label", title);
            const field = document.createElement("span");
            field.className = "hr-date-field";
            input.before(field);
            field.append(input);
            input.classList.add("sr-only");
            input.tabIndex = -1;
            if (endInput) {
                endInput.dataset.payrollDate = "true";
                field.append(endInput);
                endInput.classList.add("sr-only");
                endInput.tabIndex = -1;
            }
            const id = "hr-calendar-" + ++nextId;
            const trigger = document.createElement("button");
            trigger.className = "hr-field manual-date-trigger";
            trigger.type = "button";
            trigger.setAttribute("aria-label", title + ": chọn ngày");
            trigger.setAttribute("aria-haspopup", "dialog");
            trigger.setAttribute("aria-expanded", "false");
            trigger.setAttribute("aria-controls", id);
            trigger.innerHTML = `<span data-manual-date-label>Chọn ngày</span>${icon("calendar")}`;
            const panel = document.createElement("div");
            panel.id = id;
            panel.className = "hr-calendar manual-date-popover";
            panel.setAttribute("popover", "auto");
            panel.setAttribute("role", "dialog");
            panel.setAttribute("aria-label", title + ": chọn ngày");
            panel.innerHTML = `<div class="hr-calendar-heading">
                <button class="hr-button hr-icon-button" type="button" data-calendar-previous aria-label="Tháng trước">${icon("arrow-left")}</button>
                <strong data-calendar-title aria-live="polite"></strong>
                <button class="hr-button hr-icon-button" type="button" data-calendar-next aria-label="Tháng sau">${icon("arrow-right")}</button>
                </div><div class="hr-calendar-weekdays" aria-hidden="true">${["T2", "T3", "T4", "T5", "T6", "T7", "CN"].map((day) => `<span>${day}</span>`).join("")}</div>
                <div class="hr-calendar-days" data-calendar-days role="group" aria-label="Các ngày trong tháng"></div>
                ${endInput ? '<p class="sr-only" data-calendar-range-hint aria-live="polite"></p>' : ""}
                <footer class="hr-calendar-footer"><button class="hr-button" type="button" data-date-clear>Xóa ngày</button><button class="hr-button" type="button" data-calendar-today>Hôm nay</button></footer>`;
            field.append(trigger, panel);
            const picker = window.MoAdminDatePicker.create(field, {
                input,
                field,
                endInput,
                maxRangeDays: 93,
                compactLaptop: true,
            });
            const dateInputs = endInput ? [input, endInput] : [input];
            for (const dateInput of dateInputs) {
                dateInput.addEventListener("input", picker.refresh);
                dateInput.addEventListener("change", picker.refresh);
                dateInput.addEventListener("invalid", () => trigger.focus());
            }
            input.form?.addEventListener("reset", () => {
                picker.close();
                setTimeout(picker.refresh, 0);
            });
            panel.querySelector("[data-date-clear]").addEventListener("click", () => {
                input.value = "";
                if (endInput) {
                    endInput.value = "";
                    endInput.dispatchEvent(new Event("input", { bubbles: true }));
                    endInput.dispatchEvent(new Event("change", { bubbles: true }));
                }
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.dispatchEvent(new Event("change", { bubbles: true }));
                picker.close(true);
            });
            new MutationObserver(() => {
                trigger.disabled = input.disabled;
            }).observe(input, { attributes: true, attributeFilter: ["disabled"] });
            trigger.disabled = input.disabled;
            picker.refresh();
        });
    }

    window.MoPayrollDates = { enhance };
})();
