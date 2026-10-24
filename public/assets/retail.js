(() => {
    "use strict";
    const root = document.querySelector("[data-retail-page]");
    if (!root) return;
    const one = (selector) => root.querySelector(selector);
    const abilities = window.LANG_HOME?.abilities || [];
    const can = (key) => abilities.includes(key);
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[char]);
    const cash = (value) => Number(value).toLocaleString("vi-VN") + "đ";
    const dateTime = (value) => value ? new Date(value.replace(" ", "T") + "Z").toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" }) : "—";
    const status = one("[data-retail-status]");
    const productMode = root.dataset.mode === "products";
    const historyMode = root.dataset.mode === "history";
    const form = one("[data-product-form]");
    const dialog = one("[data-sale-dialog]");
    const detail = one("[data-sale-detail]");
    let products = [];
    let saleRooms = [];
    let cart = new Map();
    let busy = false;
    let loading = false;
    let page = 1;
    let sale = null;
    let requestId = "";
    let requestSignature = "";
    let trigger = null;
    let detailVersion = 0;

    async function request(path, method = "GET", data = null) {
        const response = await fetch("/api/admin/" + path, {
            method,
            headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
            ...(data ? { body: JSON.stringify(data) } : {}),
        });
        let result = {};
        try { result = await response.json(); } catch {}
        if (!response.ok) throw new Error(result.error || "Chưa kết nối được bán hàng.");
        return result;
    }
    function renderProducts() {
        const target = one("[data-products]");
        if (!target) return;
        const search = (one("[data-retail-search]")?.value || "").toLocaleLowerCase("vi-VN");
        const visible = products.filter((product) => product.name.toLocaleLowerCase("vi-VN").includes(search));
        target.innerHTML = visible.map((product) => `<article class="rounded-xl border border-[#e5dacb] bg-white p-4">
            <h3 class="font-sans text-base font-semibold">${escape(product.name)}</h3>
            <p class="mt-2 font-semibold text-[#79543e]">${cash(product.price)}</p>
            ${productMode ? `<p class="mt-1 text-xs text-stone-500">${Number(product.is_active) ? "Đang bán" : "Ngừng bán"}</p>` : ""}
            ${productMode
                ? (can("products.edit") ? `<button class="timeline-button mt-3" type="button" data-edit-product="${escape(product.id)}">Sửa sản phẩm</button>` : "")
                : `<button class="timeline-button mt-3" type="button" data-add-product="${escape(product.id)}">Thêm vào đơn</button>`}
        </article>`).join("") || '<p class="text-sm text-stone-500">Chưa có sản phẩm phù hợp. Thêm và bật sản phẩm trong Quản lý sản phẩm.</p>';
    }
    function renderCart(resetTotal = true) {
        const target = one("[data-cart]");
        if (!target) return;
        let total = 0;
        target.innerHTML = [...cart.values()].map(({ product, quantity }) => {
            total += Number(product.price) * quantity;
            return `<div class="grid grid-cols-[1fr_auto] items-center gap-2 border-b border-[#e5dacb] pb-3">
                <div><strong class="text-sm">${escape(product.name)}</strong><p class="mt-1 text-xs text-stone-500">${cash(product.price)} / sản phẩm</p></div>
                <button class="timeline-button" type="button" data-remove-product="${escape(product.id)}" aria-label="Bỏ ${escape(product.name)}">Bỏ</button>
                <label class="grid gap-1 text-xs">Số lượng<input class="timeline-input max-w-24" type="number" min="1" max="999" step="1" value="${quantity}" data-quantity="${escape(product.id)}"></label>
                <strong class="text-sm">${cash(Number(product.price) * quantity)}</strong>
            </div>`;
        }).join("") || '<p class="text-sm text-stone-500">Chọn sản phẩm bên trái để bắt đầu.</p>';
        one("[data-cart-listed]").textContent = "Tổng theo bảng giá: " + cash(total);
        if (resetTotal || !can("sales.price") || cart.size === 0) one("[data-cart-total]").value = total || "";
        one("[data-cart-total]").disabled = busy || loading;
        one("[data-sale-room]").disabled = busy || loading;
        one("[data-sale-create]").disabled = busy || loading || cart.size === 0;
    }
    async function loadHistory() {
        if (!can("sales.view") || !historyMode) return;
        const query = new URLSearchParams({ page: String(page), q: one("[data-sales-filter]")?.value.trim() || "" });
        const result = await request("sales?" + query);
        one("[data-sales-revenue]").textContent = "Doanh thu đã thu (tất cả): " + cash(result.revenue);
        one("[data-sales-history]").innerHTML = result.sales.length ? `<table><thead><tr><th>Mã đơn</th><th>Phòng</th><th>Ngày tạo</th><th>Giá chốt</th><th>Thanh toán</th><th>Thao tác</th></tr></thead><tbody>${result.sales.map((item) => `<tr>
            <td class="font-semibold">${escape(item.sale_code)}</td><td>${escape(item.room_name_snapshot || "Không gắn phòng")}${item.booking_code_snapshot ? `<span class="mt-1 block text-xs text-stone-500">${escape(item.booking_code_snapshot)}</span>` : ""}</td><td>${escape(dateTime(item.created_at))}</td>
            <td>${cash(item.total)}</td><td>${item.status === "PAID" ? "Đã thanh toán" : "Chờ thanh toán"}</td>
            <td><button class="timeline-button" type="button" data-sale-open="${escape(item.id)}">Xem đơn / QR</button></td>
        </tr>`).join("")}</tbody></table>` : '<p class="text-sm text-stone-500">Chưa có đơn bán hàng.</p>';
        one("[data-sales-page]").textContent = `Trang ${result.page} · ${result.count} đơn`;
        one("[data-sales-prev]").disabled = page <= 1;
        one("[data-sales-next]").disabled = page * 30 >= result.count;
    }
    async function load() {
        if (loading || busy) return;
        loading = true;
        status.textContent = "Đang tải…";
        try {
            if (productMode || (!historyMode && can("sales.create"))) {
                const result = await request(productMode ? "products" : "sales/catalog");
                products = result.products;
                saleRooms = result.rooms || [];
                const roomSelect = one("[data-sale-room]");
                if (roomSelect) {
                    const previousRoom = roomSelect.value;
                    roomSelect.innerHTML = '<option value="">Không gắn phòng</option>'
                        + (result.rooms || []).map((room) => `<option value="${escape(room.id)}">${escape(room.name)} · ${escape(room.activeBooking?.booking_code || "Không có đơn đang ở")}</option>`).join("");
                    if ((result.rooms || []).some((room) => room.id === previousRoom)) roomSelect.value = previousRoom;
                }
                cart = new Map();
                renderProducts();
                renderCart();
            }
            await loadHistory();
            status.textContent = "";
        } catch (error) {
            status.textContent = error.message;
        } finally {
            loading = false;
            if (!productMode) renderCart(false);
        }
    }
    function showSale(value, warning = "") {
        sale = value;
        const paid = sale.status === "PAID";
        const safeLink = typeof sale.checkout_url === "string" && /^https:\/\/pay\.payos\.vn\//.test(sale.checkout_url);
        detail.innerHTML = `<p class="text-lg font-semibold">${escape(sale.sale_code)}</p>
            <p class="text-sm font-semibold">Phòng: ${escape(sale.room_name_snapshot || "Không gắn phòng")}</p>
            ${sale.booking_code_snapshot ? `<p class="text-sm">Đơn đặt phòng: <strong>${escape(sale.booking_code_snapshot)}</strong></p>` : ""}
            <p class="text-sm">${escape(dateTime(sale.created_at))} · ${paid ? "Đã thanh toán" : "Chờ thanh toán"}</p>
            ${sale.items.map((item) => `<p class="flex justify-between gap-3 text-sm"><span>${escape(item.product_name)} × ${Number(item.quantity)}</span><strong>${cash(Number(item.unit_price) * Number(item.quantity))}</strong></p>`).join("")}
            <p class="text-sm">Theo bảng giá: ${cash(sale.listed_total)}</p><p class="text-xl font-semibold">Giá chốt: ${cash(sale.total)}</p>
            ${paid ? '<p class="text-sm text-emerald-800">Đã tính vào doanh thu · ' + escape(dateTime(sale.paid_at)) + '</p>' : '<p class="text-sm text-stone-600">Đơn chưa tính doanh thu. Mở mã QR trên payOS để khách chuyển khoản.</p>'}
            ${warning ? `<p class="text-sm text-red-800" role="status">${escape(warning)}</p>` : ""}
            <div class="flex flex-wrap gap-2">
                ${!paid && safeLink ? `<a class="timeline-button timeline-primary" href="${escape(sale.checkout_url)}" target="_blank" rel="noopener noreferrer">Mở mã QR payOS ↗</a>` : ""}
                ${!paid && can("sales.create") ? `<button class="timeline-button" type="button" data-sale-action="${safeLink ? "check" : "payment"}">${safeLink ? "Kiểm tra thanh toán" : "Tạo lại QR"}</button>` : ""}
            </div><p class="text-sm text-stone-600" data-sale-message role="status"></p>`;
        if (!dialog.open) dialog.showModal();
    }
    function resetForm() {
        if (!form) return;
        form.reset();
        form.elements.id.value = "";
        form.hidden = !can("products.create");
    }
    form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        const data = { id: form.elements.id.value, name: form.elements.name.value.trim(), price: Number(form.elements.price.value), isActive: form.elements.isActive.checked };
        busy = true;
        let saved = false;
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            await request("products", data.id ? "PATCH" : "POST", data);
            resetForm();
            saved = true;
            status.textContent = "Đã lưu sản phẩm.";
        } catch (error) {
            status.textContent = error.message;
        } finally {
            busy = false;
            submit.disabled = false;
        }
        if (saved) {
            await load();
            status.textContent = "Đã lưu sản phẩm.";
        }
    });
    one("[data-retail-search]")?.addEventListener("input", renderProducts);
    one("[data-sales-filter]")?.addEventListener("change", () => { if (!busy && !loading) { page = 1; historyPage(); } });
    one("[data-retail-refresh]").addEventListener("click", load);
    one("[data-product-reset]")?.addEventListener("click", () => { if (!busy) resetForm(); });
    root.addEventListener("change", (event) => {
        const input = event.target.closest("[data-quantity]");
        if (!input || busy) return;
        const quantity = Number(input.value);
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 999) {
            status.textContent = "Số lượng phải từ 1 đến 999.";
            renderCart(false);
            return;
        }
        cart.get(input.dataset.quantity).quantity = quantity;
        renderCart();
    });
    root.addEventListener("click", async (event) => {
        if (busy || loading) return;
        const add = event.target.closest("[data-add-product]");
        const remove = event.target.closest("[data-remove-product]");
        const edit = event.target.closest("[data-edit-product]");
        const open = event.target.closest("[data-sale-open]");
        const action = event.target.closest("[data-sale-action]");
        if (add) {
            const product = products.find((item) => item.id === add.dataset.addProduct);
            const current = cart.get(product.id);
            cart.set(product.id, { product, quantity: Math.min(999, (current?.quantity || 0) + 1) });
            renderCart();
        } else if (remove) {
            cart.delete(remove.dataset.removeProduct);
            renderCart();
        } else if (edit) {
            const product = products.find((item) => item.id === edit.dataset.editProduct);
            form.hidden = false;
            form.elements.id.value = product.id;
            form.elements.name.value = product.name;
            form.elements.price.value = product.price;
            form.elements.isActive.checked = !!Number(product.is_active);
            form.elements.name.focus();
        } else if (open) {
            trigger = open;
            const active = ++detailVersion;
            detail.innerHTML = '<p role="status">Đang tải đơn…</p>';
            dialog.showModal();
            try {
                const result = await request("sales/" + open.dataset.saleOpen);
                if (active === detailVersion && dialog.open) showSale(result.sale);
            } catch (error) {
                if (active === detailVersion && dialog.open) detail.textContent = error.message;
            }
        } else if (event.target.closest("[data-sale-create]")) {
            if (!cart.size || !one("[data-cart-total]").reportValidity()) return;
            const roomId = one("[data-sale-room]").value || "";
            const bookingId = saleRooms.find((room) => room.id === roomId)?.activeBooking?.id || "";
            const data = { roomId, bookingId, items: [...cart.values()].map(({ product, quantity }) => ({ productId: product.id, quantity })), total: Number(one("[data-cart-total]").value) };
            const signature = JSON.stringify(data);
            if (requestSignature !== signature || !requestId) {
                requestId = crypto.randomUUID().replaceAll("-", "");
                requestSignature = signature;
            }
            busy = true;
            renderCart(false);
            status.textContent = "Đang lưu đơn và tạo QR…";
            trigger = one("[data-sale-create]");
            try {
                const result = await request("sales", "POST", { ...data, requestId });
                showSale(result.sale, result.paymentError || "");
                cart.clear();
                requestId = "";
                requestSignature = "";
                status.textContent = "Đã lưu đơn bán hàng.";
                await loadHistory();
            } catch (error) {
                status.textContent = error.message;
            } finally {
                busy = false;
                renderCart(false);
            }
        } else if (action && sale) {
            busy = true;
            action.disabled = true;
            try {
                const result = await request("sales/" + sale.id + "/" + action.dataset.saleAction, "POST");
                showSale(result.sale);
                await loadHistory();
            } catch (error) {
                one("[data-sale-message]").textContent = error.message;
            } finally {
                busy = false;
                if (action.isConnected) action.disabled = false;
            }
        } else if (event.target.closest("[data-sales-prev]")) {
            page = Math.max(1, page - 1);
            await historyPage();
        } else if (event.target.closest("[data-sales-next]")) {
            page++;
            await historyPage();
        }
    });
    async function historyPage() {
        loading = true;
        try { await loadHistory(); } catch (error) { status.textContent = error.message; } finally { loading = false; }
    }
    function close() {
        if (busy) return;
        detailVersion++;
        dialog.close();
        if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    }
    one("[data-sale-close]").addEventListener("click", close);
    dialog.addEventListener("cancel", (event) => { event.preventDefault(); close(); });
    resetForm();
    load();
})();
