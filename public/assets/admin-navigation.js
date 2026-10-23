(() => {
    "use strict";

    const sidebar = document.querySelector("#admin-sidebar");
    const toggle = document.querySelector("[data-admin-menu-toggle]");
    const backdrop = document.querySelector("[data-admin-menu-backdrop]");
    const close = document.querySelector("[data-admin-menu-close]");
    const workspace = document.querySelector(".admin-workspace");
    if (!sidebar || !toggle || !backdrop || !close || !workspace) return;

    const desktop = window.matchMedia("(min-width: 1280px)");
    let opened = false;
    let previousInert = false;

    function closeMenu(restoreFocus = true) {
        if (opened) workspace.inert = previousInert;
        opened = false;
        sidebar.removeAttribute("data-open");
        sidebar.removeAttribute("role");
        sidebar.removeAttribute("aria-modal");
        if (desktop.matches) sidebar.removeAttribute("aria-hidden");
        else sidebar.setAttribute("aria-hidden", "true");
        toggle.setAttribute("aria-expanded", "false");
        backdrop.hidden = true;
        document.body.classList.remove("admin-menu-open");
        if (restoreFocus && !desktop.matches) toggle.focus({ preventScroll: true });
    }

    function openMenu() {
        if (desktop.matches || opened) return;
        opened = true;
        previousInert = workspace.inert;
        sidebar.setAttribute("data-open", "");
        sidebar.removeAttribute("aria-hidden");
        sidebar.setAttribute("role", "dialog");
        sidebar.setAttribute("aria-modal", "true");
        toggle.setAttribute("aria-expanded", "true");
        backdrop.hidden = false;
        document.body.classList.add("admin-menu-open");
        workspace.inert = true;
        close.focus({ preventScroll: true });
    }

    toggle.addEventListener("click", () => opened ? closeMenu() : openMenu());
    close.addEventListener("click", () => closeMenu());
    backdrop.addEventListener("click", () => closeMenu());
    sidebar.addEventListener("click", (event) => {
        if (opened && event.target.closest(".admin-navigation a, .admin-navigation button")) {
            closeMenu();
        }
    });
    document.addEventListener("keydown", (event) => {
        if (!opened) return;
        if (event.key === "Escape") {
            event.preventDefault();
            closeMenu();
        } else if (event.key === "Tab") {
            const controls = [...sidebar.querySelectorAll("a[href], button, input, select, textarea, [tabindex]")]
                .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });
    desktop.addEventListener("change", () => closeMenu(false));
    window.addEventListener("pagehide", () => closeMenu(false));
    closeMenu(false);
})();
