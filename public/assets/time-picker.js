((scope) => {
    "use strict";

    const pad = (value) => String(value).padStart(2, "0");
    function parse(value) {
        if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value)) {
            throw new RangeError("Giờ nhận phòng phải có định dạng HH:mm từ 00:00 đến 23:59.");
        }
        return value.split(":").map(Number);
    }
    function minuteOptions(current) {
        return [...new Set([...Array.from({ length: 12 }, (_, index) => index * 5), current])]
            .sort((a, b) => a - b);
    }

    function create(dialog, options = {}) {
        const root = options.root || dialog.querySelector(".manual-time-picker");
        const panel = options.panel || dialog.querySelector("#manual-time-popover");
        const hourList = panel.querySelector("[data-time-hours]");
        const minuteList = panel.querySelector("[data-time-minutes]");
        const exact = panel.querySelector("[data-time-exact]");
        const fields = options.fields || dialog.querySelector("form").elements;
        const triggers = [...root.querySelectorAll("[data-time-trigger]")];
        let hour = 0;
        let minute = 0;
        let opened = false;
        let disabled = false;
        let activeTrigger = triggers[0];

        function render() {
            const option = (value, unit, selected) => `<button type="button" role="option" aria-selected="${selected}" tabindex="${selected ? 0 : -1}" data-time-${unit}="${value}"><span>${pad(value)} ${unit === "hour" ? "giờ" : "phút"}</span><span class="manual-time-check" aria-hidden="true"></span></button>`;
            hourList.innerHTML = Array.from({ length: 24 }, (_, index) => option(index, "hour", index === hour)).join("");
            minuteList.innerHTML = minuteOptions(minute).map((value) => option(value, "minute", value === minute)).join("");
            root.querySelector("[data-time-hour-label]").textContent = pad(hour) + " giờ";
            root.querySelector("[data-time-minute-label]").textContent = pad(minute) + " phút";
            exact.value = minute;
        }

        function set(value) {
            [hour, minute] = parse(value);
            fields.arrivalHour.value = pad(hour);
            fields.arrivalMinute.value = pad(minute);
            fields.time.value = `${pad(hour)}:${pad(minute)}`;
            render();
        }

        function reflectOpen(value) {
            opened = value;
            triggers.forEach((button) => button.setAttribute("aria-expanded", String(value)));
        }

        function close(restoreFocus = false) {
            exact.value = minute;
            if (opened && typeof panel.hidePopover === "function") panel.hidePopover();
            panel.classList.remove("is-open");
            reflectOpen(false);
            if (restoreFocus) activeTrigger.focus();
        }

        function position() {
            if (!opened) return;
            const rect = root.getBoundingClientRect();
            const viewportHeight = window.visualViewport?.height || window.innerHeight;
            const viewportWidth = window.visualViewport?.width || window.innerWidth;
            const below = viewportHeight - rect.bottom - 16;
            const above = rect.top - 16;
            const flip = below < 300 && above > below;
            const height = Math.max(120, Math.min(480, flip ? above : below));
            const width = Math.min(440, viewportWidth - 24);
            panel.style.width = width + "px";
            panel.style.height = height + "px";
            panel.style.left = Math.max(12, Math.min(rect.left, viewportWidth - width - 12)) + "px";
            panel.style.top = (flip ? rect.top - height - 8 : rect.bottom + 8) + "px";
        }

        function focusSelected(list) {
            const selected = list.querySelector('[aria-selected="true"]');
            selected?.focus({ preventScroll: true });
            selected?.scrollIntoView({ block: "nearest" });
        }

        function commit(value) {
            set(value);
            fields.time.dispatchEvent(new Event("input", { bubbles: true }));
            fields.time.dispatchEvent(new Event("change", { bubbles: true }));
        }

        triggers.forEach((button) => button.addEventListener("click", () => {
            if (disabled) return;
            if (opened && activeTrigger === button) return close(true);
            activeTrigger = button;
            if (!opened) {
                if (typeof panel.showPopover === "function") panel.showPopover();
                else panel.classList.add("is-open");
            }
            reflectOpen(true);
            position();
            focusSelected(button.dataset.timeTrigger === "hour" ? hourList : minuteList);
        }));
        panel.addEventListener("toggle", (event) => reflectOpen(event.newState === "open"));
        panel.addEventListener("click", (event) => {
            const option = event.target.closest("[data-time-hour], [data-time-minute]");
            if (!option || disabled) return;
            if (option.hasAttribute("data-time-hour")) {
                commit(`${pad(option.dataset.timeHour)}:${pad(minute)}`);
                focusSelected(minuteList);
            } else {
                commit(`${pad(hour)}:${pad(option.dataset.timeMinute)}`);
                close(true);
            }
        });
        exact.addEventListener("wheel", (event) => {
            if (document.activeElement === exact) event.preventDefault();
        }, { passive: false });
        exact.addEventListener("change", () => {
            if (disabled || exact.value === "" || !exact.reportValidity()) return;
            commit(`${pad(hour)}:${pad(Number(exact.value))}`);
            close(true);
        });
        exact.addEventListener("keydown", (event) => {
            if (event.key !== "Enter") return;
            event.preventDefault();
            if (exact.value === "" || !exact.reportValidity()) return;
            commit(`${pad(hour)}:${pad(Number(exact.value))}`);
            close(true);
        });
        panel.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                event.preventDefault();
                event.stopPropagation();
                close(true);
                return;
            }
            const list = event.target.closest('[role="listbox"]');
            if (!list) return;
            const options = [...list.querySelectorAll('[role="option"]')];
            const index = options.indexOf(document.activeElement);
            const step = list === hourList ? 12 : 1;
            const next = {
                ArrowDown: index + 1, ArrowUp: index - 1,
                ArrowRight: index + step, ArrowLeft: index - step,
                Home: 0, End: options.length - 1,
            }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            const target = options[Math.max(0, Math.min(options.length - 1, next))];
            options.forEach((option) => { option.tabIndex = option === target ? 0 : -1; });
            target.focus();
        });
        document.addEventListener("pointerdown", (event) => {
            if (opened && !root.contains(event.target) && !panel.contains(event.target)) close();
        });
        window.addEventListener("resize", position);
        window.visualViewport?.addEventListener("resize", position);
        document.addEventListener("scroll", (event) => {
            if (opened && !panel.contains(event.target)) position();
        }, { capture: true, passive: true });
        dialog.addEventListener("close", () => close());
        render();
        return {
            set,
            close,
            setDisabled(value) {
                disabled = value;
                triggers.forEach((button) => { button.disabled = value; });
                if (value) close();
            },
        };
    }

    scope.MoTimePicker = { create, parse, minuteOptions };
    if (typeof module !== "undefined") module.exports = scope.MoTimePicker;
})(typeof window === "undefined" ? globalThis : window);
