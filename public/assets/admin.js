(() => {
    const shell = document.querySelector(".admin-shell");
    if (!shell || !document.querySelector("[data-admin-rooms]")) return;
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
        if (!response.ok) {
            const error = new Error(response.status === 401
                ? "Phiên đăng nhập đã hết hạn hoặc không còn hợp lệ. Vui lòng đăng nhập lại."
                : data.error || "Không thể kết nối máy chủ.");
            error.status = response.status;
            throw error;
        }
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
        if (!LANG_HOME.permissions.includes(name)) {
            name = LANG_HOME.permissions.includes("bookings") ? "bookings" : "rooms";
        }
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
            name === "rooms" ? "Quản lý phòng" : "Đặt phòng";
        if (name === "rooms") loadRooms();
        else loadBookings();
    }
    all("[data-admin-tab]").forEach(
        (button) => (button.onclick = () => selectTab(button.dataset.adminTab)),
    );

    let bookings = [];
    document.addEventListener("mo:booking-stay-saved", () => loadBookings());
    const bookingFilters = { query: "", status: "all", room: "all", from: "", to: "", showFinished: false };
    const statusNames = {
        PENDING: "Chờ thanh toán",
        CONFIRMED: "Đã xác nhận",
        CHECKED_IN: "Đang lưu trú",
        CHECKED_OUT: "Đã kết thúc",
        CANCELLED: "Đã hủy",
        EXPIRED: "Hết hạn giữ chỗ",
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
        if (!LANG_HOME.permissions.includes("bookings")) return;
        if (!bookingPanel) return;
        bookingPanel.setAttribute("aria-busy", "true");
        try {
            if (!LANG_HOME.abilities.includes("bookings.view")) {
                bookingPanel.innerHTML = '<p class="notice">Bạn chưa được cấp quyền xem danh sách đặt phòng.</p>';
                return;
            }
            const data = await request("/api/admin/bookings");
            if (!Array.isArray(data.bookings)) {
                throw new Error("Không nhận được danh sách đặt phòng từ máy chủ.");
            }
            bookings = data.bookings;
            const roomNames = [...new Set(bookings.map((item) => item.room_name))];
            bookingPanel.innerHTML = `
              <section class="admin-booking-panel" aria-labelledby="booking-list-title">
                  <div class="admin-table-heading"><div><h2 id="booking-list-title">Danh sách đặt phòng <span class="admin-count">${bookings.length}</span></h2></div><span>${bookings.length} đơn</span></div>
                  <div class="admin-filters">
                      <label class="admin-search">Tìm đặt phòng<input type="search" data-booking-search placeholder="Tên không dấu, một phần SĐT, mã đơn, tên phòng…" value="${esc(bookingFilters.query)}"></label>
                      <label>Phòng<select data-booking-room><option value="all">Tất cả phòng</option>${roomNames.map((name) => `<option value="${esc(name)}">${esc(name)}</option>`).join("")}</select></label>
                      <label>Trạng thái<select data-booking-status><option value="all">Tất cả trạng thái</option>${Object.entries(
                          statusNames,
                      )
                          .map(([value, label]) => `<option value="${value}">${label}</option>`)
                          .join("")}</select></label>
                      <button type="button" data-booking-refresh class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg border border-[#d2bda7] bg-white text-[#70432c] enabled:hover:bg-[#eee4d6] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e] disabled:cursor-wait disabled:opacity-50" aria-label="Làm mới danh sách đặt phòng" title="Làm mới danh sách đặt phòng">${icon("refresh")}</button>
                  </div>
                  <div class="flex flex-wrap items-center gap-3 border-t border-stone-100 px-5 py-4">
                      <button type="button" data-range-open class="button outline small gap-2" aria-haspopup="dialog">${icon('calendar')} <span data-range-label>Khoảng ngày lưu trú</span></button>
                      <button type="button" data-range-clear class="button outline small">Bỏ lọc ngày</button>
                      <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-show-finished ${bookingFilters.showFinished ? 'checked' : ''}> Hiện cả đơn đã xong</label>
                      <span data-filter-count class="text-sm text-stone-600" aria-live="polite"></span>
                  </div>
                  <dialog data-range-dialog aria-labelledby="booking-range-title" class="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-2xl overflow-auto rounded-2xl border border-stone-200 bg-white p-5 text-stone-800 shadow-xl backdrop:bg-black/40">
                      <h3 id="booking-range-title" class="text-lg font-semibold">Chọn khoảng ngày lưu trú</h3>
                      <p data-range-selection class="my-3 text-sm" aria-live="polite"></p>
                      <div class="flex items-center justify-between gap-3">
                          <button type="button" data-range-prev class="button outline small" aria-label="Tháng trước">←</button>
                          <span class="text-sm text-stone-500">Chọn ngày bắt đầu rồi ngày kết thúc</span>
                          <button type="button" data-range-next class="button outline small" aria-label="Tháng sau">→</button>
                      </div>
                      <div data-range-months class="my-4 grid gap-5 sm:grid-cols-2"></div>
                      <div class="flex flex-wrap justify-between gap-3 border-t border-stone-200 pt-4">
                          <button type="button" data-range-today class="button outline small">Hôm nay</button>
                          <div class="flex gap-2"><button type="button" data-range-cancel class="button outline small">Bỏ qua</button><button type="button" data-range-apply class="button small">Áp dụng</button></div>
                      </div>
                  </dialog>
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
            setupBookingRange();
            one('[data-show-finished]').addEventListener('change', event => {
                bookingFilters.showFinished = event.target.checked;
                bookingPagination.reset();
                renderBookings();
            });
            renderBookings();
            bookingPanel.onclick = bookingAction;
        } catch (error) {
            const authError = error.status === 401;
            const guidance = authError || error.status === 403 ? "" : " Hãy kiểm tra kết nối và chọn Làm mới.";
            const action = authError
                ? '<a class="timeline-button" href="/auth/login">Đăng nhập lại</a>'
                : `<button type="button" data-booking-refresh class="timeline-button gap-2">${icon("refresh")} Làm mới danh sách</button>`;
            bookingPanel.innerHTML = `<div class="notice error" role="alert">${esc(error.message)}${guidance}</div>${action}`;
        } finally {
            bookingPanel.setAttribute("aria-busy", "false");
            one("[data-booking-refresh]", bookingPanel)?.addEventListener("click", async (event) => {
                const button = event.currentTarget;
                if (button.disabled) return;
                button.disabled = true;
                button.setAttribute("aria-busy", "true");
                await loadBookings();
                one("[data-booking-refresh]", bookingPanel)?.focus({ preventScroll: true });
            });
        }
    }
    const bookingPagination = MoPagination.create("bookings", renderBookings);
    const roomPagination = MoPagination.create("rooms", renderRooms, 9);
    function renderBookings() {
        const filtered = bookings.filter(item => MoBookingFilters.matches(item, bookingFilters));
        one('[data-filter-count]').textContent = `${filtered.length} / ${bookings.length} đơn`;
        const view = bookingPagination.slice(filtered);
        one("[data-booking-results]").innerHTML = filtered.length
            ? `<div class="admin-table-scroll" tabindex="0" role="region" aria-label="Danh sách đặt phòng"><table class="admin-booking-table booking-work-table"><thead><tr><th scope="col">Ảnh phòng</th><th scope="col">Mã / Khách hàng</th><th scope="col">Phòng / Thời gian</th><th scope="col">Thanh toán</th><th scope="col">Trạng thái</th><th scope="col">Thao tác</th></tr></thead><tbody>${view.items
                  .map(
                      (booking) => renderBookingRow(booking),
                  )
                  .join("")}</tbody></table></div>${bookingPagination.render(view, "đơn")}`
            : '<div class="empty-state" role="status">Chưa có đặt phòng phù hợp. Thử đổi bộ lọc hoặc làm mới danh sách.</div>';
    }
    function setupBookingRange() {
        const dialog = one('[data-range-dialog]');
        const open = one('[data-range-open]');
        const today = new Intl.DateTimeFormat('sv-SE', {timeZone: 'Asia/Ho_Chi_Minh'}).format(new Date());
        let first = '', last = '', month = new Date(today + 'T12:00:00Z');
        const key = date => `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
        const pretty = value => value ? value.split('-').reverse().join('/') : 'Chưa chọn';
        function updateLabel() {
            one('[data-range-label]').textContent = bookingFilters.from
                ? `${pretty(bookingFilters.from)} – ${pretty(bookingFilters.to)}` : 'Khoảng ngày lưu trú';
        }
        function draw() {
            one('[data-range-selection]').textContent = `Từ ${pretty(first)} · Đến ${pretty(last || first)}`;
            one('[data-range-apply]').disabled = !first;
            one('[data-range-months]').innerHTML = [0, 1].map(offset => {
                const date = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + offset, 1));
                const year = date.getUTCFullYear(), m = date.getUTCMonth();
                const count = new Date(Date.UTC(year, m + 1, 0)).getUTCDate();
                const blank = (date.getUTCDay() + 6) % 7;
                let days = '<span></span>'.repeat(blank);
                for (let d = 1; d <= count; d++) {
                    const value = key(new Date(Date.UTC(year, m, d)));
                    const selected = value === first || value === last;
                    const inside = first && last && value > first && value < last;
                    days += `<button type="button" data-range-day="${value}" aria-label="${pretty(value)}" aria-pressed="${selected}" class="min-h-10 rounded-lg text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800 ${selected ? 'bg-emerald-700 font-semibold text-white' : inside ? 'bg-emerald-50 text-emerald-900 hover:bg-emerald-100' : 'text-stone-800 hover:bg-stone-100'}">${d}</button>`;
                }
                return `<section><h4 class="mb-3 text-center font-semibold">Tháng ${m + 1} ${year}</h4><div class="grid grid-cols-7 text-center text-xs text-stone-500" aria-hidden="true">${['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].map(day => `<span class="py-2">${day}</span>`).join('')}</div><div class="grid grid-cols-7 gap-1">${days}</div></section>`;
            }).join('');
        }
        open.addEventListener('click', () => {
            first = bookingFilters.from;
            last = bookingFilters.to;
            month = new Date((first || today) + 'T12:00:00Z');
            draw();
            dialog.showModal();
        });
        dialog.addEventListener('click', event => {
            const day = event.target.closest('[data-range-day]');
            if (!day) return;
            const value = day.dataset.rangeDay;
            if (!first || last) { first = value; last = ''; }
            else { last = value < first ? first : value; first = value < first ? value : first; }
            draw();
            dialog.querySelector(`[data-range-day="${value}"]`)?.focus();
        });
        for (const [selector, offset] of [['[data-range-prev]', -1], ['[data-range-next]', 1]]) {
            one(selector).addEventListener('click', () => {
                month = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + offset, 1));
                draw();
            });
        }
        one('[data-range-today]').addEventListener('click', () => {
            first = last = today;
            month = new Date(today + 'T12:00:00Z');
            draw();
        });
        one('[data-range-cancel]').addEventListener('click', () => dialog.close());
        one('[data-range-apply]').addEventListener('click', () => {
            bookingFilters.from = first;
            bookingFilters.to = last || first;
            updateLabel();
            bookingPagination.reset();
            renderBookings();
            dialog.close();
        });
        one('[data-range-clear]').addEventListener('click', () => {
            bookingFilters.from = bookingFilters.to = '';
            updateLabel();
            bookingPagination.reset();
            renderBookings();
        });
        dialog.addEventListener('close', () => open.focus());
        updateLabel();
    }
    function bookingSuccessLink(booking) {
        if (!["CONFIRMED", "CHECKED_IN", "CHECKED_OUT"].includes(booking.status)
            || !/^(?:MO|LANG)-[A-F0-9]{10}$/.test(booking.booking_code || "")
            || !/^[a-f0-9]{64}$/.test(booking.access_token || "")) return "";
        return `${location.origin}/booking-success/${booking.booking_code}#${booking.access_token}`;
    }
    function renderBookingRow(booking) {
        const successLink = bookingSuccessLink(booking);
        const source = { PHONE: "Điện thoại", ZALO: "Zalo", WEBSITE: "Website" }[booking.booking_source] || "Website";
        const payment = {
            UNPAID: "Chưa thanh toán", PENDING: "Chờ thanh toán", PAID: "Đã thanh toán",
            FAILED: "Thanh toán lỗi", REFUNDED: "Đã hoàn tiền",
        }[booking.payment_status] || "Chưa thanh toán";
        const method = { cash: "Tiền mặt", bank_transfer: "Chuyển khoản", payos: "payOS" }[booking.payment_method] || "";
        const canCollect = LANG_HOME.abilities.includes("bookings.payment")
            && !Number(booking.is_test)
            && booking.payment_status !== "REFUNDED"
            && (booking.payment_status !== "PAID" || booking.status === "CANCELLED")
            && (["PENDING", "EXPIRED", "CONFIRMED", "CHECKED_IN", "CANCELLED"].includes(booking.status)
                || (["PHONE", "ZALO"].includes(booking.booking_source) && booking.status === "CHECKED_OUT"));
        const action = (status, label, symbol, danger = false) => `
            <button type="button" ${danger ? 'class="booking-cancel"' : ""} data-booking-action="${status}" data-id="${esc(booking.id)}" aria-label="${label} đơn ${esc(booking.booking_code)}">${icon(symbol)} ${label}</button>`;
        let actions = "";
        if (["PENDING", "EXPIRED"].includes(booking.status)) actions = action("CANCELLED", "Hủy đơn", "x", true);
        if (booking.status === "CONFIRMED") actions = action("CHECKED_IN", "Nhận sớm", "key") + action("CANCELLED", "Hủy", "x", true);
        if (booking.status === "CHECKED_IN" && LANG_HOME.abilities.includes("availability.checkout")) {
            actions = `<a class="timeline-button" href="/admin/availability#booking-${encodeURIComponent(booking.id)}">Trả phòng & phụ thu</a>`;
        }
        if (LANG_HOME.abilities.includes("bookings.create") && ["CONFIRMED", "CHECKED_IN"].includes(booking.status)
            && !Number(booking.is_test) && !booking.continued_to_booking_id) {
            actions += `<button type="button" class="timeline-button" data-booking-continuation="${esc(booking.id)}">Ở tiếp / thêm gói</button>`;
        }
        if (LANG_HOME.manualDateOverride === true && ["PENDING", "CONFIRMED", "CHECKED_IN"].includes(booking.status)
            && !booking.continued_from_booking_id && !booking.continued_to_booking_id) {
            actions += `<button type="button" class="timeline-button" data-booking-stay="${esc(booking.id)}">Đổi phòng / sửa lịch</button>`;
        }
        if (LANG_HOME.isSuperAdmin === true && ["CHECKED_IN", "CHECKED_OUT"].includes(booking.status)
            && !booking.continued_to_booking_id) {
            actions += action("CANCELLED", "Hủy đơn", "x", true);
        }
        if (successLink) {
            actions += `<button type="button" data-copy-booking-link="${esc(booking.id)}" class="timeline-button gap-2" title="Sao chép link xác nhận đặt phòng" aria-label="Sao chép link xác nhận đơn ${esc(booking.booking_code)}">${icon("copy")} Sao chép link</button>`;
        }
        const identity = Number(booking.identity_image_count) > 0
            ? (LANG_HOME.abilities.includes('bookings.identity')
                ? `<button type="button" data-view-identity="${esc(booking.id)}" class="min-h-10 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">Xem CCCD</button>`
                : '<span class="text-xs text-stone-500">Đã lưu CCCD</span>')
            : '<span class="text-xs text-stone-500">Chưa có CCCD</span>';
        return `<tr data-booking-tone="${MoBookingFilters.tone(booking)}">
            <td class="booking-photo-cell"><img class="booking-room-photo" src="${esc(booking.room_image || '/images/white-illustration.jpg')}" alt="Phòng ${esc(booking.room_name)}" width="104" height="78" loading="lazy"></td>
            <td class="booking-guest-cell"><div class="booking-cell">
                <strong class="booking-code">${esc(booking.booking_code)}</strong>
                ${Number(booking.is_test) ? '<span class="booking-source">ĐƠN THỬ · Không có giao dịch thật</span>' : ""}
                <span class="booking-meta booking-guest">${icon("user")} ${esc(booking.guest_name)}</span>
                <div class="booking-contact-row">
                    <a class="booking-meta booking-phone" href="tel:${esc(booking.guest_phone)}">${icon("phone")} ${esc(booking.guest_phone)}</a>
                    <span class="booking-source">${icon(booking.booking_source === "PHONE" ? "phone" : "message-circle")} ${source}</span>
                </div>
                ${identity}
                ${booking.guest_note ? `<details class="booking-note"><summary>Ghi chú khách</summary><p>${esc(booking.guest_note)}</p></details>` : ""}
            </div></td>
            <td class="booking-stay-cell"><div class="booking-cell">
                <div class="booking-room-row">
                    <strong class="booking-meta booking-room">${icon("bed")} ${esc(booking.room_name)}</strong>
                    ${booking.package_name_snapshot ? `<span class="booking-meta">${icon("clock")} ${esc(booking.package_name_snapshot)}</span>` : ""}
                </div>
                <div class="booking-stay"><span class="booking-stay-label">Nhận</span><time>${bookingDate(booking.check_in)}</time></div>
                <div class="booking-stay"><span class="booking-stay-label">Trả</span><time>${bookingDate(booking.check_out)}</time></div>
            </div></td>
            <td class="booking-payment-cell"><div class="booking-cell">
                <strong class="booking-amount">${cash(booking.total_price)}</strong>
                <span class="booking-payment-status" data-payment="${esc(booking.payment_status)}">${icon(booking.payment_status === "PAID" ? "check" : "clock")} ${payment}</span>
                ${method ? `<span class="booking-meta">${icon("wallet")} ${method}</span>` : ""}
                ${canCollect ? `<button type="button" class="button outline small booking-collect" data-booking-payment="${esc(booking.id)}">${icon("wallet")} Xác nhận nhận tiền</button>` : ""}
                ${booking.failure_reason ? `<span class="admin-reconcile">${esc(booking.failure_reason)}</span>` : ""}
            </div></td>
            <td class="booking-status-cell"><div class="booking-cell"><span class="admin-booking-status booking-status-label" data-status="${esc(booking.status)}"><span class="booking-status-dot" aria-hidden="true"></span>${esc(booking.isOverdue ? "Quá giờ · Chưa trả phòng" : statusNames[booking.status] || booking.status)}</span>${MoBookingFilters.needsAttention(booking) ? '<strong class="text-sm text-red-800">Cần đối soát thanh toán</strong>' : ''}<span class="booking-meta">${icon(booking.statusSource === "MANUAL" ? "user" : "clock")}${booking.statusSource === "MANUAL" ? "Admin cập nhật" : "Tự động theo giờ"}</span></div></td>
            <td class="booking-actions-cell"><div class="admin-actions">${actions || '<span class="booking-ended">—</span>'}</div></td>
        </tr>`;
    }
    async function bookingAction(event) {
        const copyLinkButton = event.target.closest("[data-copy-booking-link]");
        if (copyLinkButton) {
            const booking = bookings.find((item) => item.id === copyLinkButton.dataset.copyBookingLink);
            const link = booking && bookingSuccessLink(booking);
            if (!link) return;
            copyLinkButton.disabled = true;
            try {
                await navigator.clipboard.writeText(link);
                LangUI.toast({ type: "success", title: "Đã sao chép link", message: "Bạn có thể dán vào Zalo để gửi riêng cho khách của đơn này." });
            } catch {
                const input = document.createElement("textarea");
                input.value = link;
                input.className = "fixed -left-full top-0";
                input.setAttribute("readonly", "");
                document.body.append(input);
                try {
                    input.select();
                    if (!document.execCommand("copy")) throw new Error("Clipboard unavailable");
                    LangUI.toast({ type: "success", title: "Đã sao chép link", message: "Bạn có thể dán vào Zalo để gửi riêng cho khách của đơn này." });
                } catch {
                    LangUI.toast({ type: "error", title: "Chưa sao chép được", message: "Trình duyệt đang chặn sao chép. Vui lòng cho phép quyền bộ nhớ tạm rồi thử lại." });
                } finally {
                    input.remove();
                    copyLinkButton.focus();
                }
            } finally {
                copyLinkButton.disabled = false;
            }
            return;
        }
        const stayButton = event.target.closest("[data-booking-stay]");
        if (stayButton && LANG_HOME.manualDateOverride === true) {
            const booking = bookings.find((item) => item.id === stayButton.dataset.bookingStay);
            if (booking) document.dispatchEvent(new CustomEvent("mo:edit-booking-stay", { detail: { booking, trigger: stayButton } }));
            return;
        }
        const identityButton = event.target.closest('[data-view-identity]');
        if (identityButton) {
            const id = identityButton.dataset.viewIdentity;
            let viewer = document.querySelector('[data-identity-viewer]');
            if (viewer) viewer.remove();
            viewer = document.createElement('dialog');
            viewer.dataset.identityViewer = '';
            viewer.className = 'm-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-4xl overflow-auto rounded-2xl border border-stone-200 bg-white p-5 text-stone-800 shadow-xl backdrop:bg-black/40';
            viewer.innerHTML = `<div class="flex items-center justify-between gap-4"><h2 class="text-lg font-semibold" id="identity-viewer-title">CCCD của người thuê</h2><button type="button" data-identity-close class="min-h-11 rounded-lg border border-stone-300 px-4 py-2 hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">Đóng</button></div><div class="mt-4 grid gap-4 sm:grid-cols-2">${[['front', 'Mặt trước'], ['back', 'Mặt sau']].map(([side, label]) => `<section><h3 class="mb-2 font-medium">${label}</h3><img class="max-h-[60dvh] w-full rounded-lg border border-stone-200 object-contain" src="/api/admin/bookings/${encodeURIComponent(id)}/identity/${side}" alt="CCCD ${label.toLowerCase()}"><p data-identity-load-error class="mt-2 text-sm text-red-800" hidden>Không tải được ảnh. Kiểm tra phiên đăng nhập và quyền xem CCCD.</p></section>`).join('')}</div>`;
            viewer.setAttribute('aria-labelledby', 'identity-viewer-title');
            viewer.querySelectorAll('img').forEach(image => image.addEventListener('error', () => {
                image.hidden = true;
                image.nextElementSibling.hidden = false;
            }));
            document.body.append(viewer);
            viewer.querySelector('[data-identity-close]').onclick = () => viewer.close();
            viewer.addEventListener('close', () => {
                viewer.querySelectorAll('img').forEach(image => image.removeAttribute('src'));
                viewer.remove();
                if (identityButton.isConnected) identityButton.focus();
            }, {once: true});
            viewer.showModal();
            return;
        }
        const paymentButton = event.target.closest("[data-booking-payment]");
        if (paymentButton) {
            const booking = bookings.find((item) => item.id === paymentButton.dataset.bookingPayment);
            if (!booking) return;
            const method = booking.payment_method === "cash" ? "Tiền mặt" : "Chuyển khoản";
            const confirmed = await LangUI["confirm"]({
                title: "Xác nhận đã nhận đủ tiền?",
                message: `${booking.booking_code} · ${cash(booking.total_price)} · ${method}. Chỉ xác nhận sau khi đã nhận đủ tiền của khách.`,
                confirmLabel: "Đã nhận đủ tiền",
            });
            if (!confirmed) return;
            paymentButton.disabled = true;
            try {
                const result = await request(`/api/admin/bookings/${encodeURIComponent(booking.id)}/payment`, mutation("POST", {
                    paymentMethod: booking.payment_method || "bank_transfer",
                    received: true,
                }));
                LangUI.toast({ type: "success", title: "Đã ghi nhận thanh toán", message: result.accessReady
                    ? `${booking.booking_code} · Đã giữ lịch và có mã cổng.`
                    : `${booking.booking_code} · Đã lưu thanh toán; mã cổng/email đang chờ xử lý. Có thể thử lại ở Mã mở cửa.` });
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
                        : "Đơn sẽ bị hủy, ngừng giữ lịch và tiền của đơn được loại khỏi doanh thu. Lịch sử thanh toán vẫn được lưu; thao tác này không tự hoàn tiền cho khách."),
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
        if (!LANG_HOME.permissions.includes("rooms")) return;
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
                          `<article class="admin-room-card"><div class="admin-room-cover"><img src="${esc(room.image)}" alt=""><span class="badge status-${room.status.toLowerCase()}">${room.status === "COMING_SOON" ? "Sắp ra mắt" : room.status === "HIDDEN" ? "Đang ẩn" : "Đang hoạt động"}</span></div><div class="admin-room-content"><div>
                              <div class="admin-room-heading">
                                  <h3>${esc(room.name)}</h3>
                                  <span class="admin-room-path">/rooms/${esc(room.slug)}</span>
                              </div>
                              <p class="admin-room-description">${esc(room.subtitle)}</p>
                          </div><div class="chip-row">${room.tags.map((tag) => `<span class="chip">${esc(tag)}</span>`).join("")}</div><ul class="admin-package-list mt-auto" aria-label="Gói lưu trú">${room.packages.map((pkg) => {
                              const base = Number(pkg.basePrice ?? pkg.price);
                              const percent = Number(pkg.effectiveDiscountPercent || 0);
                              const sale = percent > 0 && base > Number(pkg.price);
                              return `<li class="admin-package-row">
                                  <div class="admin-package-label">
                                      <span class="admin-package-name">${esc(pkg.name)}</span>
                                      ${sale ? `<span class="admin-package-discount">Giảm ${esc(percent)}%</span>` : ''}
                                  </div>
                                  <div class="admin-package-price">
                                      ${sale ? `<del><span class="sr-only">Giá gốc </span>${cash(base)}</del>` : ''}
                                      <strong><span class="sr-only">Giá hiện tại </span>${cash(pkg.price)}</strong>
                                  </div>
                              </li>`;
                          }).join("") || '<li class="admin-package-row text-sm text-[#736b61]">Chưa có gói giá</li>'}</ul><div class="admin-card-actions"><button class="button outline small" type="button" data-room-visibility="${esc(room.id)}" aria-label="${room.status === "HIDDEN" ? "Hiển thị" : "Ẩn"} phòng ${esc(room.name)}" title="${room.status === "HIDDEN" ? "Hiển thị với khách" : "Ẩn khỏi website"}">${icon(room.status === "HIDDEN" ? "eye" : "eye-off")} ${room.status === "HIDDEN" ? "Hiển thị" : "Ẩn phòng"}</button><a class="button outline small" href="/admin/rooms/${encodeURIComponent(room.id)}/preview" target="_blank" rel="noopener">${icon("external-link")} Xem trước</a><button class="button outline small" data-edit-room="${esc(room.id)}">${icon("pencil")} Sửa</button><button class="icon-button danger-text" data-delete-room="${esc(room.id)}" aria-label="Xóa phòng">${icon("trash")}<span class="admin-delete-label">Xóa</span></button></div></div></article>`,
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
    let packageTimeSequence = 0;
    const packageTimeEvents = new Map();
    function initPackageTimes(row) {
        const events = new AbortController();
        packageTimeEvents.set(row, events);
        for (const key of ["checkInTime", "checkOutTime"]) {
            const input = one(`[data-package-field="${key}"]`, row);
            const label = key === "checkInTime" ? "Nhận phòng" : "Trả phòng";
            const id = `package-time-${++packageTimeSequence}`;
            const field = document.createElement("div");
            field.className = "field manual-time-field";
            field.dataset.fixedField = "";
            field.innerHTML = `<span>${label} · 24 giờ</span>
                <div class="manual-time-picker">
                    <button type="button" data-time-trigger="hour" aria-label="${label}: chọn giờ" aria-haspopup="dialog" aria-controls="${id}" aria-expanded="false"><span data-time-hour-label>Chọn giờ</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                    <span aria-hidden="true">:</span>
                    <button type="button" data-time-trigger="minute" aria-label="${label}: chọn phút" aria-haspopup="dialog" aria-controls="${id}" aria-expanded="false"><span data-time-minute-label>Phút</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                </div>
                <div id="${id}" class="manual-time-popover" popover="auto" role="dialog" aria-label="${label}: chọn giờ 24 giờ">
                    <section class="manual-time-column"><h4 id="${id}-hours">Chọn giờ</h4><div class="manual-hour-options" role="listbox" aria-labelledby="${id}-hours" data-time-hours></div></section>
                    <section class="manual-time-column manual-minute-column"><h4 id="${id}-minutes">Chọn phút</h4><div class="manual-minute-options" role="listbox" aria-labelledby="${id}-minutes" data-time-minutes></div><label class="manual-exact-minute">Phút chính xác<input type="number" min="0" max="59" step="1" inputmode="numeric" data-time-exact></label></section>
                </div>`;
            input.closest("label").replaceWith(field);
            input.type = "hidden";
            field.prepend(input);
            const picker = MoTimePicker.create(field, {
                root: one(".manual-time-picker", field),
                panel: one(".manual-time-popover", field),
                fields: { time: input, arrivalHour: { value: "" }, arrivalMinute: { value: "" } },
                signal: events.signal,
            });
            if (input.value) picker.set(input.value);
        }
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
        packageList.append(row);
        initPackageTimes(row);
        togglePackageMode(row);
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
                ? `Trong ngày nhận phòng được ưu đãi: ${cash(percent === 0 ? base : Math.round(base * (100 - percent) / 100000) * 1000)}` : "";
        }
    }
    form.addEventListener("input", updateDiscountPreviews);
    function togglePackageMode(row) {
        const fixed = one('[data-package-field="mode"]', row).value === "FIXED_TIME";
        row.classList.toggle("package-fixed-time", fixed);
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
        packageTimeEvents.forEach((events) => events.abort());
        packageTimeEvents.clear();
        packageList.innerHTML = "";
        form.elements.id.value = room?.id || "";
        for (const key of [
            "name",
            "slug",
            "subtitle",
            "tag",
            "description",
            "maxGuests",
            "bedrooms",
            "bathrooms",
            "sortOrder",
            "discountPercent",
            "discountStart",
            "discountEnd",
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
        window.MoAdminPermissions?.refresh();
        setEditorInert(true);
        document.body.classList.add("dialog-open");
        requestAnimationFrame(() => {
            if (room && !LANG_HOME.abilities.includes("rooms.edit")) {
                one("[data-image-input]")?.focus();
            } else {
                form.elements.name.focus();
            }
        });
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
        if (editing && !LANG_HOME.abilities.includes("rooms.visibility")) {
            payload.status = editing.status;
        }
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
            if (editing && !LANG_HOME.abilities.includes("rooms.edit"))
                result = { room: editing };
            else if (editing)
                result = await request(
                    "/api/admin/rooms/" + encodeURIComponent(editing.id),
                    mutation("PATCH", payload),
                );
            else result = await request("/api/admin/rooms", mutation("POST", payload));
            editing = result.room;
            if (LANG_HOME.abilities.includes("rooms.images")) {
                await imageDraft.save(editing.id, (method, url, body) =>
                    request(url, mutation(method, body)),
                );
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
            const row = button.closest(".package-row");
            packageTimeEvents.get(row)?.abort();
            packageTimeEvents.delete(row);
            row.remove();
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
