(() => {
    "use strict";

    const form = document.querySelector("[data-login-form]");
    if (!form) return;
    const email = form.elements.email;
    const password = form.elements.password;
    const toggle = form.querySelector("[data-login-password-toggle]");
    const remember = form.querySelector("[data-login-remember]");
    const storageKey = "mo-home-login-email";

    toggle.hidden = false;
    toggle.addEventListener("click", () => {
        const visible = password.type === "password";
        password.type = visible ? "text" : "password";
        toggle.setAttribute("aria-label", visible ? "Ẩn mật khẩu" : "Hiện mật khẩu");
        toggle.setAttribute("aria-pressed", String(visible));
        toggle.querySelector("[data-eye-slash]").toggleAttribute("hidden", !visible);
    });

    try {
        const stored = localStorage.getItem(storageKey);
        if (stored && !email.value) {
            email.value = stored;
        }
        remember.checked = Boolean(stored);
        form.querySelector("[data-login-remember-label]").hidden = false;
    } catch {
        // Login remains available when browser storage is blocked.
    }
    remember.addEventListener("change", () => {
        if (!remember.checked) {
            try {
                localStorage.removeItem(storageKey);
            } catch {
                // Storage may be blocked.
            }
        }
    });
    form.addEventListener("submit", () => {
        try {
            if (remember.checked) {
                localStorage.setItem(storageKey, email.value.trim());
            } else {
                localStorage.removeItem(storageKey);
            }
        } catch {
            // Storage is optional and never stores the password.
        }
    });
})();
