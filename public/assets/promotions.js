(() => {
    "use strict";
    const root = document.querySelector("[data-promotions]");
    if (!root) return;
    const find = (selector) => root.querySelector(selector);
    const list = find("[data-promotion-list]");
    const status = find("[data-promotion-status]");
    const editor = find("[data-promotion-editor]");
    const form = find("[data-promotion-form]");
    const error = find("[data-promotion-error]");
    const reload = find("[data-promotion-reload]");
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
    })[char]);
    let promotions = [];
    let rooms = [];
    let editingId = null;
    let saving = false;
    let loaded = false;

    async function request(url, method = "GET", body = null) {
        const response = await fetch(url, {
            method,
            cache: "no-store",
            headers: body ? { "Content-Type": "application/json", "X-CSRF-Token": window.LANG_HOME.csrf } : {},
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || "Không thể lưu hoặc tải ưu đãi. Vui lòng thử lại.");
        }
        return data;
    }

    function render() {
        if (!promotions.length) {
            list.innerHTML = `<p class="rounded-xl border border-dashed border-stone-300 p-6 text-stone-600">
                Chưa có chương trình ưu đãi. Tạo chương trình đầu tiên để bắt đầu.
            </p>`;
            return;
        }
        list.innerHTML = promotions.map((promotion, index) => {
            const dayNames = ["", "Thứ 2", "Thứ 3", "Thứ 4", "Thứ 5", "Thứ 6", "Thứ 7", "Chủ nhật"];
            const weekdays = (promotion.weekdays || []).map((day) => dayNames[day]).join(", ");
            const condition = promotion.type === "SCHEDULE"
                ? `${weekdays} · ${promotion.startTime}–${promotion.endTime} (giờ nhận phòng)`
                : `Từ ${promotion.minStays} lần lưu trú hoàn tất · trùng điện thoại hoặc email`;
            const roomIds = promotion.roomIds || [];
            const packageIds = promotion.packageIds || [];
            const scope = !roomIds.length && !packageIds.length ? "Tất cả phòng và gói" : [
                "Phòng: " + (roomIds.length
                    ? roomIds.map((id) => rooms.find((room) => room.id === id)?.name || id).join(", ")
                    : "Tất cả"),
                "Gói: " + (packageIds.length ? "Chỉ các gói được chọn" : "Tất cả trong phòng phù hợp"),
                ...packageIds.map((id) => {
                    const room = rooms.find((item) => (item.packages || []).some((pkg) => pkg.id === id));
                    const pkg = room?.packages.find((item) => item.id === id);
                    return pkg ? `${room.name} / ${pkg.name}` : `Gói ${id}`;
                }),
            ].join("; ");
            return `<article class="rounded-2xl border border-stone-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-amber-900">
                            ${promotion.type === "SCHEDULE" ? "Lịch lặp" : "Khách quay lại"}
                            · Giảm ${escape(promotion.percent)}%
                        </p>
                        <h2 class="mt-1 break-words font-sans text-lg font-semibold">
                            ${escape(promotion.name)}
                        </h2>
                    </div>
                    <span class="rounded-full bg-stone-100 px-3 py-1 text-sm">
                        ${promotion.enabled ? "Đang bật" : "Đã tắt"}
                    </span>
                </div>
                <p class="mt-3 text-sm leading-relaxed">${escape(condition)}</p>
                <p class="mt-2 text-sm text-stone-600">
                    Hiệu lực: ${escape(promotion.startDate || "Không giới hạn bắt đầu")}
                    → ${escape(promotion.endDate || "Không giới hạn kết thúc")}
                </p>
                <p class="mt-2 break-words text-sm text-stone-600">Phạm vi: ${escape(scope)}</p>
                ${form ? `<button class="timeline-button mt-4" type="button" data-promotion-edit="${index}">
                    Sửa / bật tắt<span class="sr-only"> ${escape(promotion.name)}</span>
                </button>` : ""}
            </article>`;
        }).join("");
    }

    async function load() {
        reload.disabled = true;
        status.textContent = "Đang tải ưu đãi…";
        try {
            const data = await request("/api/admin/promotions");
            promotions = data.promotions;
            rooms = data.rooms;
            loaded = true;
            render();
            status.textContent = `Có ${promotions.length} chương trình ưu đãi.`;
        } catch (failure) {
            status.textContent = failure.message;
        } finally {
            reload.disabled = false;
        }
    }

    function updateConditions() {
        const schedule = form.elements.type.value === "SCHEDULE";
        for (const [selector, active] of [["[data-promotion-schedule]", schedule], ["[data-promotion-returning]", !schedule]]) {
            const fieldset = find(selector);
            fieldset.hidden = !active;
            fieldset.disabled = !active;
        }
        const all = form.elements.allScope.checked;
        const scope = find("[data-promotion-scope]");
        scope.hidden = all;
        const selectedRooms = Array.from(scope.querySelectorAll('[name="roomIds"]:checked')).map((input) => input.value);
        scope.querySelectorAll("input").forEach((input) => {
            const outsideRooms = input.name === "packageIds"
                && selectedRooms.length > 0
                && !selectedRooms.includes(input.dataset.roomId);
            if (outsideRooms) input.checked = false;
            input.disabled = all || outsideRooms;
            input.closest("label").classList.toggle("opacity-50", outsideRooms);
        });
    }

    function openEditor(promotion = null) {
        if (saving || !loaded) return;
        editingId = promotion?.id || null;
        form.reset();
        error.hidden = true;
        find("#promotion-form-title").textContent = promotion ? "Sửa ưu đãi" : "Tạo ưu đãi";
        for (const key of ["name", "type", "percent", "startDate", "endDate", "startTime", "endTime", "minStays"]) {
            if (promotion && promotion[key] != null) form.elements[key].value = promotion[key];
        }
        form.elements.enabled.checked = promotion ? Boolean(promotion.enabled) : true;
        form.querySelectorAll('[name="weekdays"]').forEach((input) => {
            input.checked = promotion ? (promotion.weekdays || []).includes(Number(input.value)) : true;
        });
        const roomIds = promotion?.roomIds || [];
        const packageIds = promotion?.packageIds || [];
        form.elements.allScope.checked = !roomIds.length && !packageIds.length;
        find("[data-promotion-scope]").innerHTML = rooms.map((room) => `<div class="grid gap-2 rounded-xl border border-stone-200 bg-white p-3">
            <label class="flex min-h-11 items-center gap-2 text-sm font-medium">
                <input class="h-5 w-5 accent-amber-900 focus-visible:outline-2 focus-visible:outline-amber-800"
                    type="checkbox" name="roomIds" value="${escape(room.id)}"
                    ${roomIds.includes(room.id) ? "checked" : ""}>
                Lọc phòng: ${escape(room.name)}
            </label>
            ${(room.packages || []).map((pkg) => `<label class="ml-4 flex min-h-11 items-center gap-2 text-sm">
                <input class="h-4 w-4 accent-amber-900 focus-visible:outline-2 focus-visible:outline-amber-800"
                    type="checkbox" name="packageIds" data-room-id="${escape(room.id)}"
                    value="${escape(pkg.id)}" ${packageIds.includes(pkg.id) ? "checked" : ""}>
                ${escape(pkg.name)}
            </label>`).join("")}
        </div>`).join("");
        updateConditions();
        editor.hidden = false;
        find("#promotion-form-title").focus();
    }

    function closeEditor() {
        if (saving) return;
        editor.hidden = true;
        find("[data-promotion-new]")?.focus();
    }

    reload.addEventListener("click", load);
    list.addEventListener("click", (event) => {
        const button = event.target.closest("[data-promotion-edit]");
        if (button) openEditor(promotions[Number(button.dataset.promotionEdit)]);
    });
    if (form) {
        find("[data-promotion-new]").addEventListener("click", () => openEditor());
        find("[data-promotion-cancel]").addEventListener("click", closeEditor);
        form.elements.type.addEventListener("change", updateConditions);
        form.elements.allScope.addEventListener("change", updateConditions);
        find("[data-promotion-scope]").addEventListener("change", updateConditions);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (saving) return;
            error.hidden = true;
            const values = new FormData(form);
            const schedule = values.get("type") === "SCHEDULE";
            const all = form.elements.allScope.checked;
            const payload = {
                name: values.get("name").trim(),
                type: values.get("type"),
                percent: Number(values.get("percent")),
                enabled: form.elements.enabled.checked,
                startDate: values.get("startDate") || null,
                endDate: values.get("endDate") || null,
                weekdays: schedule ? values.getAll("weekdays").map(Number) : [],
                startTime: schedule ? values.get("startTime") : "00:00",
                endTime: schedule ? values.get("endTime") : "24:00",
                minStays: schedule ? 1 : Number(values.get("minStays")),
                roomIds: all ? [] : values.getAll("roomIds"),
                packageIds: all ? [] : values.getAll("packageIds"),
            };
            try {
                if (!all && !payload.roomIds.length && !payload.packageIds.length) {
                    throw new Error("Chọn ít nhất một phòng hoặc gói, hoặc bật phạm vi tất cả.");
                }
                if (schedule && !payload.weekdays.length) {
                    throw new Error("Chọn ít nhất một ngày trong tuần.");
                }
                if (schedule && payload.endTime <= payload.startTime) {
                    throw new Error("Giờ kết thúc phải lớn hơn giờ bắt đầu.");
                }
                if (payload.startDate && payload.endDate && payload.endDate < payload.startDate) {
                    throw new Error("Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.");
                }
                saving = true;
                const save = find("[data-promotion-save]");
                save.disabled = true;
                save.textContent = "Đang lưu…";
                const url = `/api/admin/promotions${editingId ? "/" + encodeURIComponent(editingId) : ""}`;
                const data = await request(url, editingId ? "PATCH" : "POST", payload);
                const index = promotions.findIndex((item) => item.id === data.promotion.id);
                if (index < 0) promotions.unshift(data.promotion);
                else promotions[index] = data.promotion;
                render();
                editor.hidden = true;
                status.textContent = `Đã lưu ưu đãi ${data.promotion.name}.`;
                find("[data-promotion-new]").focus();
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
                error.focus();
            } finally {
                saving = false;
                const save = find("[data-promotion-save]");
                save.disabled = false;
                save.textContent = "Lưu ưu đãi";
            }
        });
    }
    load();
})();
