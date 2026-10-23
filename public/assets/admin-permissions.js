(() => {
    "use strict";

    if (!document.body.classList.contains("admin-page")) return;
    const grants = new Set(window.LANG_HOME?.abilities || []);
    const can = (permission) => grants.has(permission);
    const rules = [
        ["[data-create-booking]", "bookings.create"],
        ["[data-booking-action]", "bookings.update"],
        ["[data-booking-payment]", "bookings.payment"],
        ["[data-add-room]", "rooms.create"],
        ["[data-edit-room]", ["rooms.edit", "rooms.images"]],
        ["[data-room-visibility]", "rooms.visibility"],
        ["[data-delete-room]", "rooms.delete"],
        ["[data-door-add], [data-manual-open]", "door-codes.create"],
        ["[data-door-edit]", "door-codes.edit"],
        ["[data-door-toggle]", "door-codes.toggle"],
        ["[data-door-delete]", "door-codes.delete"],
        ["[data-access-retry], [data-manual-retry]", "door-codes.retry"],
    ];

    function deny(element, denied) {
        element.toggleAttribute("data-permission-denied", denied);
        element.inert = denied;
    }

    function refresh() {
        for (const [selector, permission] of rules) {
            const allowed = Array.isArray(permission) ? permission.some(can) : can(permission);
            document.querySelectorAll(selector).forEach((element) => deny(element, !allowed));
        }
        const form = document.querySelector("[data-room-form]");
        if (form) {
            const editing = Boolean(form.elements.id.value);
            form.querySelectorAll(".editor-section").forEach((section) => {
                const images = section.hasAttribute("data-room-images-section");
                deny(section, images ? !can("rooms.images") : editing && !can("rooms.edit"));
            });
            for (const name of ["visibility", "comingSoon"]) {
                const control = form.elements[name];
                if (control) deny(control.closest("label") || control, editing && !can("rooms.visibility"));
            }
        }
        document.querySelectorAll('[data-manual-booking-form] option[value="PAID"]').forEach((option) => {
            option.disabled = !can("bookings.payment");
        });
    }

    window.MoAdminPermissions = { refresh };
    refresh();
    new MutationObserver(refresh).observe(document.querySelector(".admin-workspace"), {
        childList: true,
        subtree: true,
    });
})();
