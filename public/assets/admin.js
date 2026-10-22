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
        } catch {
            throw new Error("Máy chủ trả về dữ liệu không hợp lệ. Vui lòng kiểm tra lỗi PHP hoặc liên hệ người quản trị.");
        }
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
        savingRoom = false,
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
        CHECKED_IN: "Trong giờ lưu trú",
        CHECKED_OUT: "Đã kết thúc",
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
            const data = await request("/api/admin/bookings");
            if (!Array.isArray(data.bookings)) {
                throw new Error("Không nhận được danh sách đặt phòng từ máy chủ.");
            }
            bookings = data.bookings;
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
                    "Trong giờ lưu trú",
                    bookings.filter((item) => item.status === "CHECKED_IN").length,
                    "Theo giờ đặt hoặc xác nhận của admin",
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
            ? `<div class="admin-table-scroll" tabindex="0" role="region" aria-label="Bảng đặt phòng, cuộn ngang để xem thêm"><table class="admin-booking-table"><thead><tr><th scope="col">Ảnh phòng</th><th scope="col">Mã / Khách hàng</th><th scope="col">Phòng / Thời gian</th><th scope="col">Thanh toán</th><th scope="col">Trạng thái</th><th scope="col">Thao tác</th></tr></thead><tbody>${view.items
                  .map(
                      (booking) => renderBookingRow(booking),
                  )
                  .join("")}</tbody></table></div>${bookingPagination.render(view, "đơn")}`
            : '<div class="empty-state" role="status">Chưa có đặt phòng phù hợp. Thử đổi bộ lọc hoặc làm mới danh sách.</div>';
    }
    function renderBookingRow(booking) {
        const source = { PHONE: "Điện thoại", ZALO: "Zalo", WEBSITE: "Website" }[booking.booking_source] || "Website";
        const payment = {
            UNPAID: "Chưa thanh toán", PENDING: "Chờ thanh toán", PAID: "Đã thanh toán",
            FAILED: "Thanh toán lỗi", REFUNDED: "Đã hoàn tiền",
        }[booking.payment_status] || "Chưa thanh toán";
        const method = { cash: "Tiền mặt", bank_transfer: "Chuyển khoản", payos: "payOS" }[booking.payment_method] || "";
        const canCollect = booking.payment_status === "UNPAID"
            && ["PHONE", "ZALO"].includes(booking.booking_source)
            && ["CONFIRMED", "CHECKED_IN", "CHECKED_OUT"].includes(booking.status);
        const action = (status, label, symbol, danger = false) => `
            <button type="button" ${danger ? 'class="booking-cancel"' : ""} data-booking-action="${status}" data-id="${esc(booking.id)}" aria-label="${label} đơn ${esc(booking.booking_code)}">${icon(symbol)} ${label}</button>`;
        let actions = "";
        if (booking.status === "PENDING") actions = action("CANCELLED", "Hủy đơn", "x", true);
        if (booking.status === "CONFIRMED") actions = action("CHECKED_IN", "Nhận sớm", "key") + action("CANCELLED", "Hủy", "x", true);
        if (booking.status === "CHECKED_IN") actions = action("CHECKED_OUT", "Kết thúc sớm", "logout");
        return `<tr>
            <td class="booking-photo-cell"><img class="booking-room-photo" src="${esc(booking.room_image || '/images/white-illustration.jpg')}" alt="Phòng ${esc(booking.room_name)}" width="104" height="78" loading="lazy"></td>
            <td><div class="booking-cell">
                <strong class="booking-code">${esc(booking.booking_code)}</strong>
                <span class="booking-meta booking-guest">${icon("user")} ${esc(booking.guest_name)}</span>
                <div class="booking-contact-row">
                    <a class="booking-meta booking-phone" href="tel:${esc(booking.guest_phone)}">${icon("phone")} ${esc(booking.guest_phone)}</a>
                    <span class="booking-source">${icon(booking.booking_source === "PHONE" ? "phone" : "message-circle")} ${source}</span>
                </div>
                ${booking.guest_note ? `<details class="booking-note"><summary>Ghi chú khách</summary><p>${esc(booking.guest_note)}</p></details>` : ""}
            </div></td>
            <td><div class="booking-cell">
                <div class="booking-room-row">
                    <strong class="booking-meta booking-room">${icon("bed")} ${esc(booking.room_name)}</strong>
                    ${booking.package_name_snapshot ? `<span class="booking-meta">${icon("clock")} ${esc(booking.package_name_snapshot)}</span>` : ""}
                </div>
                <div class="booking-stay"><span class="booking-stay-label">Nhận</span><time>${bookingDate(booking.check_in)}</time></div>
                <div class="booking-stay"><span class="booking-stay-label">Trả</span><time>${bookingDate(booking.check_out)}</time></div>
            </div></td>
            <td><div class="booking-cell">
                <strong class="booking-amount">${cash(booking.total_price)}</strong>
                <span class="booking-payment-status" data-payment="${esc(booking.payment_status)}">${icon(booking.payment_status === "PAID" ? "check" : "clock")} ${payment}</span>
                ${method ? `<span class="booking-meta">${icon("wallet")} ${method}</span>` : ""}
                ${canCollect ? `<button type="button" class="button outline small booking-collect" data-booking-payment="${esc(booking.id)}">${icon("wallet")} Xác nhận thu tiền</button>` : ""}
                ${booking.failure_reason ? `<span class="admin-reconcile">${esc(booking.failure_reason)}</span>` : ""}
            </div></td>
            <td><div class="booking-cell"><span class="admin-booking-status booking-status-label" data-status="${esc(booking.status)}"><span class="booking-status-dot" aria-hidden="true"></span>${esc(statusNames[booking.status] || booking.status)}</span><span class="booking-meta">${icon(booking.statusSource === "MANUAL" ? "user" : "clock")}${booking.statusSource === "MANUAL" ? "Admin cập nhật" : "Tự động theo giờ"}</span></div></td>
            <td><div class="admin-actions">${actions || '<span class="booking-ended">Đã kết thúc</span>'}</div></td>
        </tr>`;
    }
    async function bookingAction(event) {
        const paymentButton = event.target.closest("[data-booking-payment]");
        if (paymentButton) {
            const booking = bookings.find((item) => item.id === paymentButton.dataset.bookingPayment);
            if (!booking) return;
            const method = booking.payment_method === "bank_transfer" ? "Chuyển khoản" : "Tiền mặt";
            const confirmed = await LangUI["confirm"]({
                title: "Xác nhận đã nhận đủ tiền?",
                message: `${booking.booking_code} · ${cash(booking.total_price)} · ${method}. Chỉ xác nhận sau khi đã nhận đủ tiền của khách.`,
                confirmLabel: "Đã nhận đủ tiền",
            });
            if (!confirmed) return;
            paymentButton.disabled = true;
            try {
                await request(`/api/admin/bookings/${encodeURIComponent(booking.id)}/payment`, mutation("POST", {
                    paymentMethod: booking.payment_method,
                }));
                LangUI.toast({ type: "success", title: "Đã ghi nhận thanh toán", message: booking.booking_code });
                await loadBookings();
            } catch (error) {
                LangUI.toast({ type: "error", title: "Chưa ghi nhận thanh toán", message: error.message });
                paymentButton.disabled = false;
            }
            return;
        }
        const button = event.target.closest("[data-booking-action]");
        if (!button || button.disabled) return;
        const booking = bookings.find((item) => item.id === button.dataset.id);
        if (!booking) return;
        const confirmation = {
            CHECKED_IN: ["Xác nhận nhận phòng sớm?", "Xác nhận nhận sớm"],
            CHECKED_OUT: ["Xác nhận kết thúc sớm?", "Xác nhận kết thúc"],
            CANCELLED: ["Xác nhận hủy đơn?", "Xác nhận hủy đơn"],
        }[button.dataset.bookingAction];
        if (!confirmation) return;
        button.disabled = true;
        const confirmed = await LangUI["confirm"]({
                title: confirmation[0],
                message: `${booking.booking_code} · ${booking.guest_name} · ${booking.room_name}. ` + (button.dataset.bookingAction === "CHECKED_IN"
                    ? "Giờ nhận phòng sẽ đổi thành thời điểm hiện tại, giờ trả và giá gói giữ nguyên. Hệ thống kiểm tra trùng lịch trước khi xác nhận."
                    : button.dataset.bookingAction === "CHECKED_OUT"
                        ? "Đơn sẽ kết thúc ngay và ngừng giữ phần thời gian còn lại. Thanh toán được giữ riêng."
                        : "Đơn sẽ bị hủy và ngừng giữ lịch. Khoản tiền đã nhận vẫn được giữ để đối soát."),
                confirmLabel: confirmation[1],
                cancelLabel: "Quay lại",
                tone: button.dataset.bookingAction === "CANCELLED" ? "danger" : "info",
            });
        if (!confirmed) {
            button.disabled = false;
            return;
        }
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
            button.disabled = false;
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
    setInterval(() => {
        const active = document.activeElement;
        if (document.hidden || bookingPanel.closest('[data-admin-panel]').hidden
            || document.querySelector("dialog[open], .ui-dialog-root.is-open")
            || bookingPanel.contains(active) || bookingPanel.getAttribute("aria-busy") === "true") return;
        loadBookings();
    }, 30000);
    window.addEventListener("mo:booking-created", () => {
        bookingFilters.query = "";
        bookingFilters.status = "all";
        bookingFilters.room = "all";
        bookingPagination.reset();
        loadBookings();
    });
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
        pkg = { ...pkg, price: pkg.basePrice ?? pkg.price };
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
        updateDiscountPreviews();
    }
    function updateDiscountPreviews() {
        const shared = Number(form.elements.discountPercent.value || 0);
        for (const row of all(".package-row", packageList)) {
            const base = Number(one('[data-package-field="price"]', row).value);
            const own = one('[data-package-field="discountPercent"]', row).value;
            const percent = own === "" ? shared : Number(own);
            const preview = one("[data-package-discount-preview]", row);
            preview.textContent = Number.isFinite(base) && Number.isInteger(percent) && percent >= 0 && percent <= 99
                ? `Sau ưu đãi: ${cash(Math.round(base * (100 - percent) / 100))}` : "";
        }
    }
    form.addEventListener("input", updateDiscountPreviews);
    function togglePackageMode(row) {
        const fixed = one('[data-package-field="mode"]', row).value === "FIXED_TIME";
        all("[data-fixed-field]", row).forEach((field) => (field.hidden = !fixed));
        one("[data-duration-field]", row).hidden = fixed;
    }
    function renderTags() {
        one("[data-tag-count]").textContent = `${tags.length} / 30`;
        one("[data-tag-status]").textContent = "";
        one("[data-tag-chips]").innerHTML = tags.length
            ? tags.map((tag, index) => `
                <li class="room-amenity-row">
                    <span class="room-amenity-row-icon">${icon("check")}</span>
                    <span class="room-amenity-name">${esc(tag)}</span>
                    <button type="button" class="icon-button danger-text" data-remove-tag="${index}" aria-label="Xóa tiện nghi ${esc(tag)}" title="Xóa tiện nghi">${icon("trash")}</button>
                </li>
            `).join("")
            : '<li class="room-amenities-empty">Chưa có tiện nghi. Nhập tên ở trên để thêm vào danh sách.</li>';
    }
    const imageDraft = MoRoomImageDraft.create();
    function renderImages() {
        const images = imageDraft.items;
        one("[data-image-count]").textContent = `(${images.length} ảnh)`;
        one("[data-image-pending]").textContent = imageDraft.pendingCount + " ảnh chờ tải";
        one("[data-image-cover-count]").textContent = images.length
            ? "1 ảnh bìa"
            : "Chưa có ảnh bìa";
        imageList.innerHTML = images.length
            ? images
                  .map(
                      (
                          image,
                          index,
                      ) => `<article class="room-image-card" data-image-card="${esc(image.key)}">
            <div class="room-image-card-top">
                <span class="room-image-grip" draggable="true" data-image-drag="${esc(image.key)}" title="Kéo để sắp xếp" aria-hidden="true">${icon("grip-vertical")}</span>
                <div class="room-image-preview"><img src="${esc(image.path)}" alt="${esc(image.caption || "Ảnh phòng " + (index + 1))}" draggable="false">${image.isCover ? `<span class="room-image-cover">${icon("crown")} Ảnh bìa</span>` : ""}</div>
                <div class="room-image-fields">
                    <label>Tên ảnh<input value="${esc(image.caption)}" data-image-caption maxlength="255" placeholder="Chú thích ảnh"></label>
                    <span class="room-image-save-state">${icon(image.file ? "clock" : "photo")}${image.file ? "Sẽ tải sau khi lưu" : "Đã tải lên"}</span>
                    <div class="room-image-card-actions">
                        <button type="button" data-cover-image="${esc(image.key)}" ${image.isCover ? "disabled" : ""}>${icon("crown")} ${image.isCover ? "Đang là ảnh bìa" : "Đặt làm ảnh bìa"}</button>
                        <button type="button" class="size-10 shrink-0" data-edit-image="${esc(image.key)}" aria-label="Chỉnh sửa ảnh" title="Chỉnh sửa ảnh">${icon("pencil")}</button>
                        <button type="button" class="room-image-delete size-10 shrink-0" data-delete-image="${esc(image.key)}" aria-label="Xóa ảnh" title="Xóa ảnh">${icon("trash")}</button>
                    </div>
                </div>
            </div>
            <div class="room-image-reorder"><span>Vị trí ${index + 1} / ${images.length}</span><div>
                <button type="button" data-move-image="${esc(image.key)}" data-direction="-1" ${index === 0 ? "disabled" : ""} aria-label="Di chuyển ảnh ${index + 1} lên trước" title="Di chuyển lên trước">${icon("arrow-left")}</button>
                <button type="button" data-move-image="${esc(image.key)}" data-direction="1" ${index === images.length - 1 ? "disabled" : ""} aria-label="Di chuyển ảnh ${index + 1} ra sau" title="Di chuyển ra sau">${icon("arrow-right")}</button>
            </div></div>
        </article>`,
                  )
                  .join("")
            : '<div class="room-images-empty">Chưa có ảnh. Chọn ảnh hoặc thả tệp vào vùng bên trên để bắt đầu.</div>';
    }
    function openEditor(room = null) {
        editorReturnFocus = document.activeElement;
        editing = room;
        manualSlug = Boolean(room);
        tags = [...(room?.tags || [])];
        imageDraft.reset(room?.images || []);
        one("[data-image-status]").textContent = "";
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
            "discountPercent",
        ])
            if (form.elements[key])
                form.elements[key].value = room?.[key] ?? form.elements[key].defaultValue;
        form.elements.visibility.value = room?.status === "HIDDEN" ? "HIDDEN" : "ACTIVE";
        form.elements.highlights.value = (room?.highlights || []).join("\n");
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
        if (savingRoom) return;
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
        imageDraft.reset();
        setDirty(false);
        editorReturnFocus?.focus();
    }
    function serialize() {
        const data = Object.fromEntries(new FormData(form));
        data.status = form.elements.comingSoon.checked
            ? "COMING_SOON"
            : form.elements.visibility.value;
        for (const key of ["maxGuests", "bedrooms", "bathrooms", "sortOrder", "discountPercent"])
            data[key] = Number(data[key]);
        data.tags = tags;
        data.highlights = form.elements.highlights.value
            .split(/\r?\n/)
            .map((text) => text.trim())
            .filter(Boolean);
        data.packages = all(".package-row", packageList).map((row) => {
            const value = {};
            for (const input of all("[data-package-field]", row)) {
                const key = input.dataset.packageField;
                value[key] = input.type === "checkbox" ? input.checked : input.value;
            }
            for (const key of ["durationMinutes", "price", "sortOrder", "discountPercent"])
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
        if (savingRoom) return;
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
        savingRoom = true;
        const controls = all("input, select, textarea, button", editor).map((element) => [
            element,
            element.disabled,
        ]);
        controls.forEach(([element]) => (element.disabled = true));
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
            await imageDraft.save(editing.id, (method, url, body) =>
                request(url, mutation(method, body)),
            );
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
            savingRoom = false;
            controls.forEach(([element, disabled]) => (element.disabled = disabled));
            renderImages();
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
    function addAmenity() {
        if (savingRoom) return;
        const input = one("[data-tag-input]");
        const tag = input.value.trim();
        let message = "";
        if (!tag) message = "Nhập tên tiện nghi trước khi thêm.";
        else if (Array.from(tag).length > 40) message = "Tên tiện nghi tối đa 40 ký tự.";
        else if (tags.length >= 30) message = "Mỗi phòng có tối đa 30 tiện nghi.";
        else if (tags.some((item) => item.toLowerCase() === tag.toLowerCase())) {
            message = "Tiện nghi này đã có trong danh sách.";
        }
        if (message) {
            one("[data-tag-status]").textContent = message;
            input.focus();
            return;
        }
        tags.push(tag);
        renderTags();
        setDirty();
        input.value = "";
        one("[data-tag-status]").textContent = `Đã thêm ${tag}. Chọn Lưu phòng để áp dụng.`;
        input.focus();
    }
    one("[data-add-tag]").onclick = addAmenity;
    one("[data-tag-input]").onkeydown = (event) => {
        if (event.key !== "Enter" || event.isComposing) return;
        event.preventDefault();
        addAmenity();
    };
    one("[data-tag-chips]").onclick = async (event) => {
        if (savingRoom) return;
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
            one("[data-tag-input]").focus();
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
        if (savingRoom || !files.length) return;
        try {
            imageDraft.add([...files]);
            renderImages();
            setDirty();
            one("[data-image-status]").textContent =
                imageDraft.pendingCount + " ảnh đang chờ tải. Chọn Lưu phòng để áp dụng.";
        } catch (error) {
            LangUI.toast({ type: "error", title: "Chưa thể thêm ảnh", message: error.message });
        }
    }
    one("[data-image-input]").onchange = (event) => {
        queueImages(event.target.files);
        event.target.value = "";
    };
    const uploadZone = one(".upload-zone", form);
    uploadZone.addEventListener("dragover", (event) => {
        event.preventDefault();
        uploadZone.classList.add("is-dragging");
    });
    uploadZone.addEventListener("dragleave", (event) => {
        if (!uploadZone.contains(event.relatedTarget)) uploadZone.classList.remove("is-dragging");
    });
    uploadZone.addEventListener("drop", (event) => {
        event.preventDefault();
        uploadZone.classList.remove("is-dragging");
        queueImages(event.dataTransfer.files);
    });
    function focusImage(key) {
        all("[data-image-card]", imageList)
            .find((card) => card.dataset.imageCard === key)
            ?.querySelector("[data-image-caption]")
            .focus();
    }
    imageList.onclick = async (event) => {
        if (savingRoom) return;
        const button = event.target.closest("button");
        if (!button || button.disabled) return;
        const card = button.closest("[data-image-card]");
        if (!card) return;
        const key = card.dataset.imageCard;
        if (button.hasAttribute("data-edit-image")) {
            focusImage(key);
            return;
        }
        if (button.hasAttribute("data-delete-image")) {
            const confirmed = await LangUI["confirm"]({
                title: "Xóa ảnh này?",
                message:
                    "Ảnh sẽ được xóa khi bạn bấm Lưu phòng. Bạn có thể Hủy biểu mẫu để giữ nguyên ảnh trên server.",
                confirmLabel: "Xóa ảnh",
                tone: "danger",
            });
            if (!confirmed || savingRoom) return;
            imageDraft.remove(key);
        } else if (button.hasAttribute("data-cover-image")) {
            imageDraft.move(key, 0);
        } else if (button.hasAttribute("data-move-image")) {
            imageDraft.move(
                key,
                imageDraft.items.findIndex((image) => image.key === key) +
                    Number(button.dataset.direction),
            );
        } else return;
        renderImages();
        setDirty();
        one("[data-image-status]").textContent = imageDraft.removedCount
            ? imageDraft.removedCount + " ảnh chờ xóa khi lưu phòng."
            : "Đã thay đổi thứ tự ảnh. Chọn Lưu phòng để áp dụng.";
        focusImage(
            imageDraft.items.some((image) => image.key === key) ? key : imageDraft.items[0]?.key,
        );
    };
    imageList.oninput = (event) => {
        if (!event.target.hasAttribute("data-image-caption") || savingRoom) return;
        imageDraft.update(event.target.closest("[data-image-card]").dataset.imageCard, {
            caption: event.target.value,
        });
        setDirty();
    };
    let draggedImage = null;
    imageList.addEventListener("dragstart", (event) => {
        const handle = event.target.closest("[data-image-drag]");
        if (!handle || savingRoom) {
            event.preventDefault();
            return;
        }
        draggedImage = handle.dataset.imageDrag;
        event.dataTransfer.setData("text/plain", draggedImage);
        event.dataTransfer.effectAllowed = "move";
        const preview = handle.closest("[data-image-card]").querySelector(".room-image-preview");
        event.dataTransfer.setDragImage(
            preview,
            preview.offsetWidth / 2,
            preview.offsetHeight / 2,
        );
    });
    imageList.addEventListener("dragover", (event) => {
        if (draggedImage && event.target.closest("[data-image-card]")) {
            event.preventDefault();
            event.dataTransfer.dropEffect = "move";
        }
    });
    imageList.addEventListener("drop", (event) => {
        if (!draggedImage || savingRoom) return;
        event.preventDefault();
        const card = event.target.closest("[data-image-card]");
        if (!card) return;
        const key = draggedImage;
        imageDraft.move(
            key,
            imageDraft.items.findIndex((image) => image.key === card.dataset.imageCard),
        );
        draggedImage = null;
        renderImages();
        setDirty();
        one("[data-image-status]").textContent =
            "Đã đổi thứ tự ảnh. Ảnh đầu tiên là ảnh bìa; chọn Lưu phòng để áp dụng.";
        focusImage(key);
    });
    imageList.addEventListener("dragend", () => {
        draggedImage = null;
    });
    window.addEventListener("beforeunload", (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    selectTab(location.hash === "#rooms" ? "rooms" : "bookings");
})();
