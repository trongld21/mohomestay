(() => {
    "use strict";

    const trigger = document.querySelector("[data-staff-email-open]");
    const dialog = document.querySelector("[data-staff-email-dialog]");
    if (!trigger || !dialog) return;
    const form = dialog.querySelector("form");
    const status = dialog.querySelector("[data-staff-email-status]");
    const submit = form.querySelector('[type="submit"]');
    const close = dialog.querySelector("[data-staff-email-close]");
    let sending = false;
    trigger.addEventListener("click", () => {
        status.textContent = "";
        dialog.showModal();
    });
    close.addEventListener("click", () => {
        if (!sending) dialog.close();
    });
    dialog.addEventListener("cancel", (event) => {
        if (sending) event.preventDefault();
    });
    dialog.addEventListener("close", () => trigger.focus());
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (sending) return;
        sending = true;
        submit.disabled = true;
        close.disabled = true;
        status.textContent = "Đang gửi email thử…";
        try {
            const response = await fetch("/api/admin/staff-email/test", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
                body: JSON.stringify({ event: form.elements.event.value }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || "Chưa gửi được email thử.");
            status.className = "mt-3 text-sm text-emerald-800";
            status.textContent = data.message;
        } catch (error) {
            status.className = "mt-3 text-sm text-red-800";
            status.textContent = error.message;
        } finally {
            sending = false;
            submit.disabled = false;
            close.disabled = false;
        }
    });
})();
