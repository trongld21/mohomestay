(() => {
    const shell = document.querySelector(".admin-shell");
    if (!shell) return;
    const one = (s, r = document) => r.querySelector(s),
        all = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (value) =>
        String(value ?? "").replace(
            /[&<>"']/g,
            (char) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#039;",
                })[char],
        );
    const icon = (name) =>
        `<svg class="admin-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><use href="#admin-icon-${name}"></use></svg>`;
    const cash = (value) => new Intl.NumberFormat("vi-VN").format(Number(value || 0)) + "đ";
    async function request(url, options = {}) {
        const form = options.body instanceof FormData;
        const response = await fetch(url, {
            ...options,
            headers: {
                ...(!form ? { "Content-Type": "application/json" } : {}),
                ...(options.headers || {}),
            },
        });
        let data = {};
        try {
            data = await response.json();
        } catch {}
        if (!response.ok) throw new Error(data.error || "Không thể kết nối máy chủ.");
        return data;
    }
    const mutation = (method, body) => ({
        method,
        headers: { "X-CSRF-Token": LANG_HOME.csrf },
        body: body instanceof FormData ? body : JSON.stringify(body),
    });
    let rooms = [],
        editing = null,
        tags = [],
        dirty = false,
        pendingFiles = [],
        manualSlug = false;
    const roomPanel = one("[data-admin-rooms]"),
        bookingPanel = one("#admin-bookings"),
        editor = one("[data-room-editor]"),
        form = one("[data-room-form]"),
        packageList = one("[data-package-list]"),
        imageList = one("[data-image-list]");

    function selectTab(name) {
        all("[data-admin-tab]").forEach((button) => {
            const selected = button.dataset.adminTab === name;
            button.classList.toggle("selected", selected);
            selected
                ? button.setAttribute("aria-current", "page")
                : button.removeAttribute("aria-current");
        });
        all("[data-admin-panel]").forEach(
            (panel) => (panel.hidden = panel.dataset.adminPanel !== name),
        );
        history.replaceState(null, "", "#" + name);
        one("[data-admin-crumb]").textContent =
            name === "rooms" ? "Quản lý phòng" : "Tổng quan & đặt phòng";
        if (name === "rooms") loadRooms();
        else loadBookings();
    }
    all("[data-admin-tab]").forEach(
        (button) => (button.onclick = () => selectTab(button.dataset.adminTab)),
    );

    let bookings = [];
    const bookingFilters = { query: "", status: "all", room: "all" };
    const statusNames = {
        PENDING: "Chờ thanh toán",
        CONFIRMED: "Đã xác nhận",
        CHECKED_IN: "Đang lưu trú",
        CHECKED_OUT: "Đã trả phòng",
        CANCELLED: "Đã hủy",
        EXPIRED: "Hết hạn",
    };
    function bookingDate(value) {
        if (!value) return "—";
        const date = new Date(value.includes("T") ? value : value.replace(" ", "T") + "Z");
        return Number.isNaN(date.getTime())
            ? "—"
            : new Intl.DateTimeFormat("vi-VN", {
                  timeZone: "Asia/Ho_Chi_Minh",
                  day: "2-digit",
                  month: "2-digit",
                  year: "numeric",
                  hour: "2-digit",
                  minute: "2-digit",
              }).format(date);
    }
    async function loadBookings() {
        if (!bookingPanel) return;
        bookingPanel.setAttribute("aria-busy", "true");
        try {
            ({ bookings } = await request("/api/admin/bookings"));
            const paid = bookings
                .filter((item) => item.payment_status === "PAID")
                .reduce((sum, item) => sum + Number(item.total_price), 0);
            const stats = [
                ["Đã thu", cash(paid), "Tổng tiền các đơn đã thanh toán"],
                [
                    "Đã xác nhận",
                    bookings.filter((item) => item.status === "CONFIRMED").length,
                    "Sẵn sàng đón khách",
                ],
                [
                    "Đang lưu trú",
                    bookings.filter((item) => item.status === "CHECKED_IN").length,
                    "Khách đang ở tại Mơ",
                ],
                [
                    "Cần đối soát",
                    bookings.filter(
                        (item) => item.payment_status === "PAID" && item.status === "CANCELLED",
                    ).length,
                    "Đơn đã hủy nhưng đã nhận tiền",
                ],
            ];
            const roomNames = [...new Set(bookings.map((item) => item.room_name))];
            bookingPanel.innerHTML = `<div class="admin-stats">${stats.map(([label, value, hint]) => `<div class="panel"><p>${esc(label)}</p><h2>${esc(value)}</h2><small>${esc(hint)}</small></div>`).join("")}</div>
              <section class="admin-booking-panel" aria-labelledby="booking-list-title">
                  <div class="admin-table-heading"><div><h2 id="booking-list-title">Danh sách đặt phòng <span class="admin-count">${bookings.length}</span></h2><p>Thông tin và trạng thái từng lượt lưu trú.</p></div><span>${bookings.length} đơn</span></div>
                  <div class="admin-filters">
                      <label class="admin-search">Tìm đặt phòng<input type="search" data-booking-search placeholder="Tên khách, số điện thoại, mã đơn…" value="${esc(bookingFilters.query)}"></label>
                      <label>Phòng<select data-booking-room><option value="all">Tất cả phòng</option>${roomNames.map((name) => `<option value="${esc(name)}">${esc(name)}</option>`).join("")}</select></label>
                      <label>Trạng thái<select data-booking-status><option value="all">Tất cả trạng thái</option>${Object.entries(
                          statusNames,
                      )
                          .map(([value, label]) => `<option value="${value}">${label}</option>`)
                          .join("")}</select></label>
                  </div>
                  <div data-booking-results></div>
              </section>`;
            one("[data-booking-room]").value = bookingFilters.room;
            if (!one("[data-booking-room]").value)
                bookingFilters.room = one("[data-booking-room]").value = "all";
            one("[data-booking-status]").value = bookingFilters.status;
            for (const [selector, key, event] of [
                ["[data-booking-search]", "query", "input"],
                ["[data-booking-room]", "room", "change"],
                ["[data-booking-status]", "status", "change"],
            ]) {
                one(selector).addEventListener(event, (e) => {
                    bookingFilters[key] = e.target.value;
                    bookingPagination.reset();
                    renderBookings();
                });
            }
            renderBookings();
            bookingPanel.onclick = bookingAction;
        } catch (error) {
            bookingPanel.innerHTML = `<div class="notice error" role="alert">${esc(error.message)} Hãy kiểm tra kết nối và chọn Làm mới.</div>`;
        } finally {
            bookingPanel.setAttribute("aria-busy", "false");
        }
    }
    const bookingPagination = MoPagination.create("bookings", renderBookings);
    const roomPagination = MoPagination.create("rooms", renderRooms, 9);
    function renderBookings() {
        const query = bookingFilters.query.trim().toLocaleLowerCase("vi");
        const filtered = bookings.filter(
            (item) =>
                (bookingFilters.status === "all" || item.status === bookingFilters.status) &&
                (bookingFilters.room === "all" || item.room_name === bookingFilters.room) &&
                [item.guest_name, item.guest_phone, item.booking_code]
                    .join(" ")
                    .toLocaleLowerCase("vi")
                    .includes(query),
        );
        const view = bookingPagination.slice(filtered);
        one("[data-booking-results]").innerHTML = filtered.length
            ? `<div class="admin-table-scroll" tabindex="0" role="region" aria-label="Bảng đặt phòng, cuộn ngang để xem thêm"><table class="admin-booking-table"><thead><tr><th scope="col">Mã / Khách hàng</th><th scope="col">Phòng / Thời gian</th><th scope="col">Thanh toán</th><th scope="col">Trạng thái</th><th scope="col">Thao tác</th></tr></thead><tbody>${view.items
                  .map(
                      (booking) => `<tr>
          <td><strong>${esc(booking.booking_code)}</strong><span>${esc(booking.guest_name)}</span><a href="tel:${esc(booking.guest_phone)}">${esc(booking.guest_phone)}</a></td>
          <td><strong>${esc(booking.room_name)}</strong><span>${bookingDate(booking.check_in)}</span><span>→ ${bookingDate(booking.check_out)}</span></td>
          <td><strong>${cash(booking.total_price)}</strong><span>${booking.payment_status === "PAID" ? "Đã thanh toán" : "Chưa thanh toán"}</span>${booking.failure_reason ? `<span class="admin-reconcile">${esc(booking.failure_reason)}</span>` : ""}</td>
          <td><span class="admin-booking-status" data-status="${esc(booking.status)}">${esc(statusNames[booking.status] || booking.status)}</span></td>
          <td><div class="admin-actions">${booking.status === "PENDING" ? `<button data-booking-action="CANCELLED" data-id="${esc(booking.id)}">Hủy đơn</button>` : ""}${booking.status === "CONFIRMED" ? `<button data-booking-action="CHECKED_IN" data-id="${esc(booking.id)}">Nhận phòng</button><button data-booking-action="CANCELLED" data-id="${esc(booking.id)}">Hủy</button>` : ""}${booking.status === "CHECKED_IN" ? `<button data-booking-action="CHECKED_OUT" data-id="${esc(booking.id)}">Trả phòng</button>` : ""}${["CANCELLED", "CHECKED_OUT", "EXPIRED"].includes(booking.status) ? "<span>—</span>" : ""}</div></td>
          </tr>`,
                  )
                  .join("")}</tbody></table></div>${bookingPagination.render(view, "đơn")}`
            : '<div class="empty-state" role="status">Chưa có đặt phòng phù hợp. Thử đổi bộ lọc hoặc làm mới danh sách.</div>';
    }
    async function bookingAction(event) {
        const button = event.target.closest("[data-booking-action]");
        if (!button) return;
        if (
            !(await LangUI["confirm"]({
                title: "Cập nhật trạng thái đơn?",
                message: "Trạng thái mới sẽ được lưu ngay.",
                confirmLabel: "Tiếp tục",
                tone: button.dataset.bookingAction === "CANCELLED" ? "danger" : "info",
            }))
        )
            return;
        try {
            await request(
                "/api/admin/bookings",
                mutation("PATCH", {
                    id: button.dataset.id,
                    status: button.dataset.bookingAction,
                }),
            );
            LangUI.toast({
                type: "success",
                title: "Đã cập nhật đơn",
                message: "Trạng thái mới đã được lưu.",
            });
            loadBookings();
        } catch (error) {
            LangUI.toast({
                type: "error",
                title: "Không thể cập nhật",
                message: error.message,
            });
        }
    }

    async function loadRooms() {
        roomPanel.innerHTML = '<div class="skeleton" style="height:260px"></div>';
        try {
            ({ rooms } = await request("/api/admin/rooms"));
            renderRooms();
        } catch (error) {
            roomPanel.innerHTML = `<p class="notice error">${esc(error.message)}</p>`;
        }
    }
    function renderRooms() {
        const query = one("[data-room-search]").value.trim().toLocaleLowerCase("vi");
        const status = one("[data-room-status]").value;
        const filtered = rooms.filter(
            (room) =>
                (status === "all" || room.status === status) &&
                (room.name + " " + room.slug).toLocaleLowerCase("vi").includes(query),
        );
        one("[data-room-count]").textContent = filtered.length + " / " + rooms.length + " phòng";
        const view = roomPagination.slice(filtered);
        one("[data-room-pagination]").innerHTML = roomPagination.render(view, "phòng");
        roomPanel.innerHTML = filtered.length
            ? view.items
                  .map(
                      (room) =>
                          `<article class="admin-room-card"><div class="admin-room-cover"><img src="${esc(room.image)}" alt=""><span class="badge status-${room.status.toLowerCase()}">${room.status === "COMING_SOON" ? "Sắp ra mắt" : room.status === "HIDDEN" ? "Đang ẩn" : "Đang hoạt động"}</span></div><div class="admin-room-content"><div><h3>${esc(room.name)}</h3><p>${esc(room.subtitle)}</p><span class="admin-room-path">/rooms/${esc(room.slug)}</span></div><div class="chip-row">${room.tags.map((tag) => `<span class="chip">${esc(tag)}</span>`).join("")}</div><div class="package-summary">${room.packages.map((pkg) => `<span>${esc(pkg.name)} <strong>${cash(pkg.price)}</strong></span>`).join("") || "<span>Chưa có gói giá</span>"}</div><div class="admin-card-actions"><button class="button outline small" type="button" data-room-visibility="${esc(room.id)}" aria-label="${room.status === "HIDDEN" ? "Hiển thị" : "Ẩn"} phòng ${esc(room.name)}" title="${room.status === "HIDDEN" ? "Hiển thị với khách" : "Ẩn khỏi website"}">${icon(room.status === "HIDDEN" ? "eye" : "eye-off")} ${room.status === "HIDDEN" ? "Hiển thị" : "Ẩn phòng"}</button><a class="button outline small" href="/admin/rooms/${encodeURIComponent(room.id)}/preview" target="_blank" rel="noopener">${icon("external-link")} Xem trước</a><button class="button outline small" data-edit-room="${esc(room.id)}">${icon("pencil")} Sửa</button><button class="icon-button danger-text" data-delete-room="${esc(room.id)}" aria-label="Xóa phòng">${icon("trash")}</button></div></div></article>`,
                  )
                  .join("")
            : '<div class="empty-state"><h3>Không có phòng phù hợp</h3><p>Thử đổi bộ lọc hoặc thêm không gian mới.</p></div>';
    }

    let editorReturnFocus = null;
    function setEditorInert(value) {
        all(".admin-sidebar, .admin-topbar, .admin-shell").forEach(
            (element) => (element.inert = value),
        );
    }
    function updateRoomUrl() {
        const slug =
            MoRoomSlug.normalize(form.elements.slug.value) ||
            MoRoomSlug.normalize(form.elements.name.value);
        const duplicate = MoRoomSlug.conflict(slug, rooms, editing?.id);
        const message = duplicate ? "Đường dẫn này đã được phòng khác sử dụng." : "";
        form.elements.slug.setCustomValidity(message);
        const error = one("[data-slug-error]");
        error.textContent = message;
        error.hidden = !message;
        form.elements.slug.setAttribute("aria-invalid", String(duplicate));
        one("[data-room-url]").textContent = slug
            ? location.origin + "/rooms/" + slug
            : "Đường dẫn sẽ được tạo từ tên phòng.";
        one("[data-slug-warning]").hidden = !editing || slug === editing.slug;
    }
    form.elements.slug.addEventListener("blur", () => {
        form.elements.slug.value = MoRoomSlug.normalize(form.elements.slug.value);
        updateRoomUrl();
    });
    one("[data-room-search]").addEventListener("input", () => {
        roomPagination.reset();
        renderRooms();
    });
    one("[data-room-status]").addEventListener("change", () => {
        roomPagination.reset();
        renderRooms();
    });
    one("[data-refresh-bookings]").onclick = loadBookings;
    document.addEventListener("keydown", (event) => {
        if (editor.hidden) return;
        if (event.key === "Escape" && !one("[data-dialog-root]")?.hasChildNodes()) closeEditor();
        if (one("[data-dialog-root]")?.hasChildNodes()) return;
        if (event.key !== "Tab") return;
        const focusable = all(
            "button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href]",
            editor,
        ).filter((element) => element.getClientRects().length && element.tabIndex >= 0);
        const first = focusable[0],
            last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    window.addEventListener("hashchange", () =>
        selectTab(location.hash === "#rooms" ? "rooms" : "bookings"),
    );
    function setDirty(value = true) {
        dirty = value;
        one("[data-dirty-indicator]").hidden = !dirty;
    }
    function addPackage(pkg = {}) {
        const row = one("[data-package-template]").content.firstElementChild.cloneNode(true);
        for (const input of all("[data-package-field]", row)) {
            const key = input.dataset.packageField;
            if (input.type === "checkbox") input.checked = pkg[key] ?? true;
            else
                input.value =
                    pkg[key] ?? (key === "sortOrder" ? (packageList.children.length + 1) * 10 : "");
        }
        togglePackageMode(row);
        packageList.append(row);
    }
    function togglePackageMode(row) {
        const fixed = one('[data-package-field="mode"]', row).value === "FIXED_TIME";
        all("[data-fixed-field]", row).forEach((field) => (field.hidden = !fixed));
        one("[data-duration-field]", row).hidden = fixed;
    }
    function renderTags() {
        one("[data-tag-chips]").innerHTML = tags
            .map(
                (tag, index) =>
                    `<button class="chip" type="button" data-remove-tag="${index}">${esc(tag)} ${icon("x")}</button>`,
            )
            .join("");
    }
    function renderImages() {
        imageList.innerHTML =
            (editing?.images || [])
                .map(
                    (image) =>
                        `<article class="image-tile" data-image-card="${esc(image.id)}"><img src="${esc(image.path)}" alt=""><div><input value="${esc(image.caption)}" data-image-caption placeholder="Chú thích"><label class="image-order">Thứ tự <input type="number" value="${Number(image.sortOrder || 0)}" data-image-order></label><div><button type="button" data-cover-image="${esc(image.id)}" ${image.isCover ? "disabled" : ""}>${image.isCover ? "Ảnh bìa" : "Đặt làm bìa"}</button><button type="button" data-delete-image="${esc(image.id)}">Xóa</button></div></div></article>`,
                )
                .join("") +
            (pendingFiles.length
                ? `<p class="notice">${pendingFiles.length} ảnh sẽ tải lên sau khi lưu.</p>`
                : "");
    }
    function openEditor(room = null) {
        editorReturnFocus = document.activeElement;
        editing = room;
        manualSlug = Boolean(room);
        tags = [...(room?.tags || [])];
        pendingFiles = [];
        form.reset();
        packageList.innerHTML = "";
        form.elements.id.value = room?.id || "";
        for (const key of [
            "name",
            "slug",
            "subtitle",
            "description",
            "maxGuests",
            "bedrooms",
            "bathrooms",
            "sortOrder",
        ])
            if (form.elements[key])
                form.elements[key].value = room?.[key] ?? form.elements[key].defaultValue;
        form.elements.visibility.value = room?.status === "HIDDEN" ? "HIDDEN" : "ACTIVE";
        form.elements.comingSoon.checked = room?.status === "COMING_SOON";
        (room?.packages || []).forEach(addPackage);
        if (!room)
            addPackage({
                name: "Khung giờ sáng",
                mode: "FIXED_TIME",
                checkInTime: "08:00",
                checkOutTime: "11:00",
                price: 0,
                enabled: true,
                sortOrder: 10,
            });
        one("[data-editor-title]").textContent = room ? "Sửa " + room.name : "Thêm phòng";
        renderTags();
        renderImages();
        updateRoomUrl();
        setDirty(false);
        editor.hidden = false;
        setEditorInert(true);
        document.body.classList.add("dialog-open");
        requestAnimationFrame(() => form.elements.name.focus());
    }
    async function closeEditor() {
        if (
            dirty &&
            !(await LangUI["confirm"]({
                title: "Bỏ thay đổi chưa lưu?",
                message: "Những nội dung vừa nhập sẽ không được lưu.",
                confirmLabel: "Bỏ thay đổi",
                tone: "danger",
            }))
        )
            return;
        editor.hidden = true;
        setEditorInert(false);
        document.body.classList.remove("dialog-open");
        editing = null;
        setDirty(false);
        editorReturnFocus?.focus();
    }
    function serialize() {
        const data = Object.fromEntries(new FormData(form));
        data.status = form.elements.comingSoon.checked
            ? "COMING_SOON"
            : form.elements.visibility.value;
        for (const key of ["maxGuests", "bedrooms", "bathrooms", "sortOrder"])
            data[key] = Number(data[key]);
        data.tags = tags;
        data.packages = all(".package-row", packageList).map((row) => {
            const value = {};
            for (const input of all("[data-package-field]", row)) {
                const key = input.dataset.packageField;
                value[key] = input.type === "checkbox" ? input.checked : input.value;
            }
            for (const key of ["durationMinutes", "price", "sortOrder"])
                value[key] = value[key] === "" ? null : Number(value[key]);
            return value;
        });
        delete data.id;
        delete data.visibility;
        delete data.comingSoon;
        return data;
    }
    async function saveRoom(event) {
        event.preventDefault();
        form.elements.slug.value =
            MoRoomSlug.normalize(form.elements.slug.value) ||
            MoRoomSlug.normalize(form.elements.name.value);
        updateRoomUrl();
        if (!form.reportValidity()) return;
        const payload = serialize();
        if (
            editing?.status === "ACTIVE" &&
            payload.status === "HIDDEN" &&
            !(await LangUI["confirm"]({
                title: "Ẩn phòng đang hoạt động?",
                message: "Phòng sẽ biến mất khỏi website và không nhận thêm booking mới.",
                confirmLabel: "Ẩn phòng",
                tone: "danger",
            }))
        )
            return;
        const button = one("[data-save-room]");
        button.disabled = true;
        button.textContent = "Đang lưu…";
        try {
            let result;
            if (editing)
                result = await request(
                    "/api/admin/rooms/" + encodeURIComponent(editing.id),
                    mutation("PATCH", payload),
                );
            else result = await request("/api/admin/rooms", mutation("POST", payload));
            editing = result.room;
            while (pendingFiles.length) {
                const body = new FormData();
                body.append("image", pendingFiles[0]);
                await request(
                    `/api/admin/rooms/${encodeURIComponent(editing.id)}/images`,
                    mutation("POST", body),
                );
                pendingFiles.shift();
            }
            setDirty(false);
            LangUI.toast({
                type: "success",
                title: "Đã lưu phòng",
                message: "Thông tin và hình ảnh đã được cập nhật.",
            });
            await loadRooms();
            editing = rooms.find((room) => room.id === editing.id) || editing;
            renderImages();
            editor.hidden = true;
            setEditorInert(false);
            document.body.classList.remove("dialog-open");
            one("[data-add-room]").focus();
        } catch (error) {
            LangUI.toast({
                type: "error",
                title: "Chưa thể lưu phòng",
                message: error.message,
                persistent: true,
            });
        } finally {
            button.disabled = false;
            button.innerHTML = icon("device-floppy") + " Lưu phòng";
        }
    }

    let visibilityBusy = false;
    async function toggleRoomVisibility(button) {
        if (visibilityBusy) return;
        const room = rooms.find((item) => item.id === button.dataset.roomVisibility);
        if (!room) return;
        visibilityBusy = true;
        const hidden = room.status !== "HIDDEN";
        try {
            if (
                hidden &&
                !(await LangUI["confirm"]({
                    title: "Ẩn phòng " + room.name + "?",
                    message:
                        "Phòng sẽ được ẩn khỏi website và không nhận đặt phòng mới. Các đơn đã đặt vẫn được giữ nguyên.",
                    confirmLabel: "Ẩn phòng",
                    cancelLabel: "Giữ hiển thị",
                    tone: "danger",
                }))
            )
                return;
            button.disabled = true;
            button.textContent = "Đang cập nhật…";
            const { room: updated } = await request(
                "/api/admin/rooms/" + encodeURIComponent(room.id) + "/visibility",
                mutation("PATCH", { status: hidden ? "HIDDEN" : "ACTIVE" }),
            );
            rooms = rooms.map((item) => (item.id === updated.id ? updated : item));
            renderRooms();
            const nextButton = all("[data-room-visibility]", roomPanel).find(
                (item) => item.dataset.roomVisibility === updated.id,
            );
            (nextButton || one("[data-room-search]")).focus();
            LangUI.toast({
                type: "success",
                title: hidden ? "Đã ẩn phòng" : "Đã hiển thị phòng",
                message: hidden
                    ? "Khách không còn thấy phòng này trên website."
                    : "Phòng đã xuất hiện trên website và có thể nhận đặt phòng.",
            });
        } catch (error) {
            renderRooms();
            LangUI.toast({
                type: "error",
                title: "Chưa thể đổi trạng thái phòng",
                message: error.message,
            });
        } finally {
            visibilityBusy = false;
        }
    }
    one("[data-add-room]").onclick = () => openEditor();
    roomPanel.onclick = async (event) => {
        const visibility = event.target.closest("[data-room-visibility]");
        if (visibility) {
            await toggleRoomVisibility(visibility);
            return;
        }
        const edit = event.target.closest("[data-edit-room]"),
            remove = event.target.closest("[data-delete-room]");
        if (edit) openEditor(rooms.find((room) => room.id === edit.dataset.editRoom));
        if (remove) {
            const room = rooms.find((item) => item.id === remove.dataset.deleteRoom);
            if (
                !(await LangUI["confirm"]({
                    title: "Xóa " + room.name + "?",
                    message: "Phòng chỉ có thể xóa khi chưa từng có đơn đặt.",
                    confirmLabel: "Xóa phòng",
                    tone: "danger",
                }))
            )
                return;
            try {
                await request(
                    "/api/admin/rooms/" + encodeURIComponent(room.id),
                    mutation("DELETE", {}),
                );
                LangUI.toast({
                    type: "success",
                    title: "Đã xóa phòng",
                    message: "Không gian đã được gỡ khỏi hệ thống.",
                });
                loadRooms();
            } catch (error) {
                LangUI.toast({
                    type: "error",
                    title: "Không thể xóa",
                    message: error.message,
                    persistent: true,
                });
            }
        }
    };
    all("[data-close-editor]").forEach((button) => (button.onclick = closeEditor));
    form.onsubmit = saveRoom;
    form.oninput = (event) => {
        setDirty();
        if (event.target === form.elements.slug) {
            manualSlug = Boolean(event.target.value);
            updateRoomUrl();
        }
        if (event.target === form.elements.name) {
            if (!manualSlug && !editing)
                form.elements.slug.value = MoRoomSlug.normalize(event.target.value);
            updateRoomUrl();
        }
        const row = event.target.closest(".package-row");
        if (row && event.target.dataset.packageField === "mode") togglePackageMode(row);
    };
    one("[data-tag-input]").onkeydown = (event) => {
        if (event.key !== "Enter" && event.key !== ",") return;
        event.preventDefault();
        const tag = event.target.value.trim().replace(/,$/, "");
        if (tag && !tags.some((item) => item.toLowerCase() === tag.toLowerCase())) {
            tags.push(tag);
            renderTags();
            setDirty();
        }
        event.target.value = "";
    };
    one("[data-tag-chips]").onclick = async (event) => {
        const button = event.target.closest("[data-remove-tag]");
        if (
            button &&
            (await LangUI["confirm"]({
                title: "Xóa tiện nghi này?",
                message:
                    "Tiện nghi " +
                    tags[Number(button.dataset.removeTag)] +
                    " sẽ được gỡ khỏi phòng sau khi lưu.",
                confirmLabel: "Xóa tiện nghi",
                tone: "danger",
            }))
        ) {
            tags.splice(Number(button.dataset.removeTag), 1);
            renderTags();
            setDirty();
        }
    };
    one("[data-add-package]").onclick = () => {
        addPackage({
            mode: "FIXED_TIME",
            checkInTime: "08:00",
            checkOutTime: "11:00",
            price: 0,
            enabled: true,
        });
        setDirty();
    };
    packageList.onclick = async (event) => {
        const button = event.target.closest("[data-remove-package]");
        if (
            button &&
            (await LangUI["confirm"]({
                title: "Xóa gói giá?",
                message: "Gói đã có booking sẽ được tắt thay vì xóa lịch sử.",
                confirmLabel: "Xóa gói",
                tone: "danger",
            }))
        ) {
            button.closest(".package-row").remove();
            setDirty();
        }
    };
    function queueImages(files) {
        const incoming = [...files];
        if (!incoming.length) return;
        const count = (editing?.images?.length || 0) + pendingFiles.length + incoming.length;
        if (
            count > 12 ||
            incoming.some(
                (file) =>
                    !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
                    file.size > 8 * 1024 * 1024,
            )
        ) {
            LangUI.toast({
                type: "error",
                title: "Chưa thể thêm ảnh",
                message: "Mỗi phòng tối đa 12 ảnh JPEG, PNG hoặc WebP; mỗi ảnh không quá 8 MiB.",
            });
            return;
        }
        pendingFiles.push(...incoming);
        renderImages();
        setDirty();
    }
    one("[data-image-input]").onchange = (event) => {
        queueImages(event.target.files);
        event.target.value = "";
    };
    const uploadZone = one(".upload-zone", form);
    uploadZone.addEventListener("dragover", (event) => event.preventDefault());
    uploadZone.addEventListener("drop", (event) => {
        event.preventDefault();
        queueImages(event.dataTransfer.files);
    });
    imageList.onclick = async (event) => {
        if (!editing) return;
        const cover = event.target.closest("[data-cover-image]"),
            remove = event.target.closest("[data-delete-image]");
        try {
            if (cover) {
                await request(
                    `/api/admin/rooms/${editing.id}/images/${cover.dataset.coverImage}`,
                    mutation("PATCH", { isCover: true }),
                );
                LangUI.toast({
                    type: "success",
                    title: "Đã đổi ảnh bìa",
                    message: "Ảnh đại diện mới đã được lưu.",
                });
                await loadRooms();
                editing = rooms.find((room) => room.id === editing.id);
                renderImages();
            }
            if (
                remove &&
                (await LangUI["confirm"]({
                    title: "Xóa ảnh này?",
                    message: "Ảnh sẽ bị xóa vĩnh viễn khỏi máy chủ.",
                    confirmLabel: "Xóa ảnh",
                    tone: "danger",
                }))
            ) {
                await request(
                    `/api/admin/rooms/${editing.id}/images/${remove.dataset.deleteImage}`,
                    mutation("DELETE", {}),
                );
                await loadRooms();
                editing = rooms.find((room) => room.id === editing.id);
                renderImages();
                LangUI.toast({
                    type: "success",
                    title: "Đã xóa ảnh",
                    message: "Thư viện ảnh đã được cập nhật.",
                });
            }
        } catch (error) {
            LangUI.toast({
                type: "error",
                title: "Không thể cập nhật ảnh",
                message: error.message,
            });
        }
    };
    imageList.onchange = async (event) => {
        const card = event.target.closest("[data-image-card]");
        if (!card || !editing) return;
        const id = card.dataset.imageCard,
            caption = one("[data-image-caption]", card).value,
            sortOrder = Number(one("[data-image-order]", card).value);
        try {
            await request(
                `/api/admin/rooms/${editing.id}/images/${id}`,
                mutation("PATCH", { caption, sortOrder }),
            );
            LangUI.toast({
                type: "success",
                title: "Đã lưu thông tin ảnh",
                message: "Chú thích và thứ tự ảnh đã được cập nhật.",
            });
            await loadRooms();
            editing = rooms.find((room) => room.id === editing.id);
            renderImages();
        } catch (error) {
            LangUI.toast({
                type: "error",
                title: "Không thể lưu",
                message: error.message,
            });
        }
    };
    window.addEventListener("beforeunload", (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    selectTab(location.hash === "#rooms" ? "rooms" : "bookings");
})();
