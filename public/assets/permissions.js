(() => {
    "use strict";

    const root = document.querySelector("[data-permission-manager]");
    const form = root?.querySelector("[data-permission-form]");
    if (!form) return;
    const find = (selector) => root.querySelector(selector);
    const accounts = JSON.parse(find("[data-permission-accounts]").textContent);
    const fields = [...form.querySelectorAll('input[name="permissions[]"]')];
    const groups = [...form.querySelectorAll("[data-permission-group]")];
    const accountLinks = [...root.querySelectorAll("[data-select-account]")];
    const normalize = (value) => value.normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[đĐ]/g, "d")
        .toLocaleLowerCase("vi-VN");
    let selected = accounts.find((account) => account.id === form.elements.id.value);
    let dirty = false;
    let saving = false;
    let switching = false;

    function update() {
        const checked = fields.filter((field) => field.checked).map((field) => field.value);
        const saved = new Set(selected.grants);
        dirty = checked.length !== saved.size || checked.some((value) => !saved.has(value));
        find("[data-permission-total]").textContent = checked.length;
        find("[data-permission-dirty]").textContent = dirty ? "Có thay đổi chưa lưu." : "Chưa có thay đổi.";
        find("[data-permission-reset]").hidden = !dirty;
        find("[data-permission-save]").disabled = !dirty;
        groups.forEach((group) => {
            const inputs = [...group.querySelectorAll('input[type="checkbox"]')];
            const count = inputs.filter((field) => field.checked).length;
            group.querySelector("[data-group-count]").textContent = `(${count}/${inputs.length})`;
            group.querySelector("[data-group-select]").disabled = count === inputs.length;
            group.querySelector("[data-group-clear]").disabled = count === 0;
        });
    }

    function filterPermissions() {
        const query = normalize(find("[data-permission-search]").value.trim());
        let matches = 0;
        groups.forEach((group) => {
            const name = group.querySelector("h3").textContent;
            const choices = [...group.querySelectorAll("[data-permission-choice]")];
            choices.forEach((choice) => {
                choice.hidden = !normalize(name + " " + choice.textContent).includes(query);
            });
            group.hidden = choices.every((choice) => choice.hidden);
            // Group operations always affect the whole group, so hide them while filtering.
            group.querySelector("[data-group-actions]").hidden = Boolean(query);
            if (!group.hidden) matches++;
        });
        find("[data-no-permissions]").hidden = matches > 0;
    }

    function showAccount(account) {
        selected = account;
        form.elements.id.value = account.id;
        fields.forEach((field) => { field.checked = account.grants.includes(field.value); });
        find("[data-selected-name]").textContent = account.name;
        find("[data-selected-email]").textContent = account.email;
        find("[data-selected-status]").textContent = account.active ? "Đang hoạt động" : "Đã khóa đăng nhập";
        accountLinks.forEach((link) => {
            if (link.dataset.selectAccount === account.id) link.setAttribute("aria-current", "true");
            else link.removeAttribute("aria-current");
        });
        find("[data-permission-search]").value = "";
        update();
        filterPermissions();
    }

    form.addEventListener("change", (event) => {
        const field = event.target;
        if (!fields.includes(field)) return;
        const group = field.closest("[data-permission-group]");
        const view = group.querySelector('input[value$=".view"]');
        if (field === view && !field.checked) {
            group.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                if (input.value !== "bookings.overview") input.checked = false;
            });
        } else if (field.checked && field.value !== "bookings.overview" && view) {
            view.checked = true;
        }
        update();
    });

    root.addEventListener("click", async (event) => {
        const link = event.target.closest("[data-select-account]");
        if (link) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (saving || switching || link.dataset.selectAccount === selected.id) return;
            const account = accounts.find((item) => item.id === link.dataset.selectAccount);
            if (!account) return;
            switching = true;
            try {
                if (dirty && !await window.LangUI["confirm"]({
                    title: "Bỏ thay đổi phân quyền?",
                    message: "Quyền vừa chọn chưa được lưu. Chuyển tài khoản sẽ bỏ các thay đổi này.",
                    confirmLabel: "Bỏ thay đổi", cancelLabel: "Tiếp tục chỉnh sửa",
                })) return;
                showAccount(account);
                const url = new URL(window.location.href);
                url.searchParams.set("account", account.id);
                url.searchParams.delete("saved");
                history.replaceState(null, "", url);
                const heading = find("[data-selected-name]");
                heading.tabIndex = -1;
                heading.focus();
            } finally {
                switching = false;
            }
            return;
        }
        const button = event.target.closest("button");
        if (!button || button.disabled || saving) return;
        if (button.hasAttribute("data-permission-reset")) {
            showAccount(selected);
            find("[data-selected-name]").tabIndex = -1;
            find("[data-selected-name]").focus();
        }
        if (button.hasAttribute("data-group-select") || button.hasAttribute("data-group-clear")) {
            const checked = button.hasAttribute("data-group-select");
            button.closest("[data-permission-group]").querySelectorAll('input[type="checkbox"]').forEach((field) => {
                field.checked = checked;
            });
            update();
        }
    });

    find("[data-account-search]").addEventListener("input", (event) => {
        const query = normalize(event.target.value.trim());
        accountLinks.forEach((link) => { link.hidden = !normalize(link.textContent).includes(query); });
        find("[data-no-accounts]").hidden = accountLinks.some((link) => !link.hidden);
    });
    find("[data-permission-search]").addEventListener("input", filterPermissions);
    form.addEventListener("submit", (event) => {
        if (saving || !dirty) {
            event.preventDefault();
            return;
        }
        saving = true;
        find("[data-permission-save]").disabled = true;
        find("[data-permission-save]").textContent = "Đang lưu…";
        form.setAttribute("aria-busy", "true");
    });
    window.addEventListener("beforeunload", (event) => {
        if (!dirty || saving) return;
        event.preventDefault();
        event.returnValue = "";
    });
    window.addEventListener("pageshow", () => {
        saving = false;
        form.removeAttribute("aria-busy");
        find("[data-permission-save]").textContent = "Lưu quyền";
        update();
    });
    update();
    filterPermissions();
})();
