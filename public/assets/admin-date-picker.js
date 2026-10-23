((scope) => {
    "use strict";

    const iso = (date) => date.toISOString().slice(0, 10);
    function parse(value) {
        const date = new Date(value + "T12:00:00Z");
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(date.getTime()) || iso(date) !== value) {
            throw new RangeError("Ngày không hợp lệ.");
        }
        return date;
    }
    function monthDays(year, month) {
        const start = new Date(Date.UTC(year, month, 1, 12));
        start.setUTCDate(1 - (start.getUTCDay() + 6) % 7);
        return Array.from({ length: 42 }, (_, index) => {
            const day = new Date(start);
            day.setUTCDate(start.getUTCDate() + index);
            return { value: iso(day), day: day.getUTCDate(), otherMonth: day.getUTCMonth() !== month };
        });
    }

    function create(dialog, options = {}) {
        const field = dialog.querySelector(".manual-date-field");
        const input = dialog.querySelector('[name="date"]');
        const trigger = field.querySelector(".manual-date-trigger");
        const panel = field.querySelector(".manual-date-popover");
        const days = panel.querySelector("[data-calendar-days]");
        const previous = panel.querySelector("[data-calendar-previous]");
        const next = panel.querySelector("[data-calendar-next]");
        const todayButton = panel.querySelector("[data-calendar-today]");
        let month;
        let focused;
        let opened = false;
        const today = () => new Intl.DateTimeFormat("en-CA", {
            timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit",
        }).format(new Date());
        const allowed = (value) => (!input.min || value >= input.min) && (!input.max || value <= input.max);

        function render() {
            const currentDay = today();
            panel.querySelector("[data-calendar-title]").textContent = new Intl.DateTimeFormat("vi-VN", {
                month: "long", year: "numeric", timeZone: "UTC",
            }).format(month);
            const currentMonth = iso(month).slice(0, 7);
            previous.disabled = Boolean(input.min && currentMonth <= input.min.slice(0, 7));
            next.disabled = Boolean(input.max && currentMonth >= input.max.slice(0, 7));
            todayButton.disabled = !allowed(currentDay);
            days.replaceChildren();
            for (const day of monthDays(month.getUTCFullYear(), month.getUTCMonth())) {
                const button = document.createElement("button");
                button.type = "button";
                button.textContent = day.day;
                button.dataset.calendarDate = day.value;
                button.dataset.otherMonth = String(day.otherMonth);
                button.dataset.today = String(day.value === currentDay);
                button.disabled = !allowed(day.value);
                button.tabIndex = day.value === focused ? 0 : -1;
                button.setAttribute("aria-label", new Intl.DateTimeFormat("vi-VN", { dateStyle: "full", timeZone: "UTC" }).format(parse(day.value)));
                button.setAttribute("aria-pressed", String(day.value === input.value));
                if (day.value === currentDay) button.setAttribute("aria-current", "date");
                days.append(button);
            }
        }

        function position() {
            if (!opened) return;
            const rect = trigger.getBoundingClientRect();
            const height = window.visualViewport?.height || window.innerHeight;
            const width = window.visualViewport?.width || window.innerWidth;
            const panelWidth = Math.min(352, width - 24);
            const below = height - rect.bottom - 16;
            const above = rect.top - 16;
            const flip = dialog.id !== "booking-app" && below < 390 && above > below;
            panel.style.width = panelWidth + "px";
            panel.style.maxHeight = dialog.id === "booking-app"
                ? "none"
                : Math.max(120, flip ? above : below) + "px";
            panel.style.left = Math.max(12, Math.min(rect.left, width - panelWidth - 12)) + "px";
            panel.style.top = (flip ? Math.max(12, rect.top - panel.offsetHeight - 8) : rect.bottom + 8) + "px";
        }

        function reflectOpen(value) {
            opened = value;
            trigger.setAttribute("aria-expanded", String(value));
        }

        function close(focus = false) {
            if (opened && typeof panel.hidePopover === "function") panel.hidePopover();
            panel.classList.remove("is-open");
            reflectOpen(false);
            if (focus) trigger.focus();
        }

        function refresh() {
            field.querySelector("[data-manual-date-label]").textContent = input.value
                ? new Intl.DateTimeFormat("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric", timeZone: "UTC" }).format(parse(input.value))
                : "Chọn ngày";
        }

        function select(value) {
            if (!allowed(value)) return;
            input.value = value;
            refresh();
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.dispatchEvent(new Event("change", { bubbles: true }));
            close(true);
        }

        trigger.addEventListener("click", () => {
            if (opened) return close(true);
            refresh();
            focused = input.value || input.min || today();
            month = parse(focused);
            month.setUTCDate(1);
            render();
            if (typeof panel.showPopover === "function") panel.showPopover();
            else panel.classList.add("is-open");
            reflectOpen(true);
            position();
            days.querySelector('[tabindex="0"]')?.focus({ preventScroll: true });
        });
        panel.addEventListener("toggle", (event) => reflectOpen(event.newState === "open"));
        function changeMonth(direction) {
            month.setUTCMonth(month.getUTCMonth() + direction);
            focused = iso(month);
            if (input.min && focused < input.min) focused = input.min;
            if (input.max && focused > input.max) focused = input.max;
            render();
            position();
        }
        previous.onclick = () => changeMonth(-1);
        next.onclick = () => changeMonth(1);
        todayButton.onclick = () => select(today());
        days.onclick = (event) => {
            const button = event.target.closest("[data-calendar-date]");
            if (button && !button.disabled) select(button.dataset.calendarDate);
        };
        panel.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                event.preventDefault();
                event.stopPropagation();
                close(true);
                return;
            }
            const button = event.target.closest("[data-calendar-date]");
            if (!button) return;
            const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
            if (step === undefined) return;
            event.preventDefault();
            const target = parse(button.dataset.calendarDate);
            target.setUTCDate(target.getUTCDate() + step);
            focused = iso(target);
            if (!allowed(focused)) return;
            month = new Date(target);
            month.setUTCDate(1);
            render();
            days.querySelector('[tabindex="0"]')?.focus({ preventScroll: true });
        });
        document.addEventListener("pointerdown", (event) => {
            if (opened && !field.contains(event.target) && !panel.contains(event.target)) close();
        }, { signal: options.signal });
        document.addEventListener("scroll", (event) => {
            if (!opened || panel.contains(event.target)) return;
            const rect = trigger.getBoundingClientRect();
            const height = window.visualViewport?.height || window.innerHeight;
            if (rect.bottom < 0 || rect.top > height) close();
            else position();
        }, { capture: true, passive: true, signal: options.signal });
        window.addEventListener("resize", position, { signal: options.signal });
        window.visualViewport?.addEventListener("resize", position, { signal: options.signal });
        dialog.addEventListener("close", () => close());
        return { refresh, close };
    }

    scope.MoAdminDatePicker = { create, parse, monthDays };
    if (typeof module !== "undefined") module.exports = scope.MoAdminDatePicker;
})(typeof window === "undefined" ? globalThis : window);
