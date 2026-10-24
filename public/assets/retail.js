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
    const inventoryMode = root.dataset.mode === "inventory";
    const stockForm = one("[data-stock-form]");
    let stockPage = 1;
    let stockRequestId = "";
    let stockSignature = "";
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
            <p class="mt-1 text-sm ${Number(product.stock_quantity) > 0 || !Number(product.track_stock) ? 'text-emerald-800' : 'text-red-800'}">${Number(product.track_stock) ? `Tồn: ${Number(product.stock_quantity)} sản phẩm` : "Dịch vụ · Không quản lý tồn"}</p>
            ${productMode ? `<p class="mt-1 text-xs text-stone-500">${Number(product.is_active) ? "Đang bán" : "Ngừng bán"}</p>` : ""}
            ${productMode
                ? (can("products.edit") ? `<button class="timeline-button mt-3" type="button" data-edit-product="${escape(product.id)}">Sửa sản phẩm</button>` : "")
                : `<button class="timeline-button mt-3" type="button" data-add-product="${escape(product.id)}">Thêm vào đơn</button>`}
        </article>`).join("") || '<p class="text-sm text-stone-500">Chưa có sản phẩm phù hợp. Thêm và bật sản phẩm trong Quản lý sản phẩm.</p>';
    }
    function renderPayable() {
        if (!one("[data-cart-payable]")) return;
        const base = Number(one("[data-cart-total]").value || 0);
        const discount = Number(one("[data-cart-discount]").value || 0);
        const valid = Number.isInteger(discount) && discount >= 0 && discount <= 100;
        one("[data-cart-payable]").textContent = valid
            ? `Khách cần trả: ${cash(Math.round(base * (100 - discount) / 100))} · Giảm ${discount}%`
            : "Nhập giảm giá từ 0 đến 100%.";
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
        one("[data-cart-discount]").disabled = busy || loading;
        renderPayable();
        one("[data-sale-create]").disabled = busy || loading || cart.size === 0;
    }
    async function loadHistory() {
        if (!can("sales.view") || !historyMode) return;
        const query = new URLSearchParams({
            page: String(page), q: one("[data-sales-filter]")?.value.trim() || "",
            from: one("[data-sales-from]").value, to: one("[data-sales-to]").value,
            status: one("[data-sales-state]").value, sort: one("[data-sales-sort]").value,
        });
        const result = await request("sales?" + query);
        one("[data-sales-revenue]").textContent = "Doanh thu trong bộ lọc: " + cash(result.revenue);
        one("[data-sales-quantity]").textContent = `Đã bán / tặng: ${Number(result.quantitySold)} sản phẩm · ${Number(result.count)} đơn trong bộ lọc`;
        one("[data-sales-products]").innerHTML = (result.productSales || []).map((item) => `<span class="rounded-lg bg-stone-100 px-3 py-2">${escape(item.product_name)}: <strong>${Number(item.quantity)}</strong></span>`).join("");
        one("[data-sales-history]").innerHTML = result.sales.length ? `<table><thead><tr><th>Mã đơn</th><th>Phòng</th><th>Ngày tạo</th><th>Giá chốt</th><th>Thanh toán</th><th>Thao tác</th></tr></thead><tbody>${result.sales.map((item) => `<tr>
            <td class="font-semibold">${escape(item.sale_code)}</td><td>${escape(item.room_name_snapshot || "Không gắn phòng")}${item.booking_code_snapshot ? `<span class="mt-1 block text-xs text-stone-500">${escape(item.booking_code_snapshot)}</span>` : ""}</td><td>${escape(dateTime(item.created_at))}</td>
            <td>${cash(item.total)}</td><td>${item.status === "CANCELLED" ? "Đã hủy" : item.status === "PAID" ? (item.completion_method === "GIFT" ? "Tặng khách" : "Đã hoàn thành") : "Chờ thanh toán"}</td>
            <td><div class="flex flex-wrap gap-2"><button class="timeline-button" type="button" data-sale-open="${escape(item.id)}">Xem đơn / QR</button>
                ${item.status === "PENDING" && can("sales.cancel") ? `<button class="timeline-button" type="button" data-sale-open="${escape(item.id)}" data-sale-intent="cancel">Hủy đơn</button>` : ""}
                ${item.status === "PENDING" && can("sales.complete") ? `<button class="timeline-button" type="button" data-sale-open="${escape(item.id)}" data-sale-intent="complete">Đã chuyển khoản trước</button>` : ""}</div></td>
        </tr>`).join("")}</tbody></table>` : '<p class="text-sm text-stone-500">Chưa có đơn bán hàng.</p>';
        one("[data-sales-page]").textContent = `Trang ${result.page} · ${result.count} đơn`;
        one("[data-sales-prev]").disabled = page <= 1;
        one("[data-sales-next]").disabled = page * 30 >= result.count;
    }
    async function loadInventory() {
        const result = await request("inventory?page=" + stockPage);
        products = result.products;
        if (stockForm) {
            const previous = stockForm.elements.productId.value;
            const tracked = products.filter((product) => Number(product.track_stock));
            stockForm.elements.productId.innerHTML = '<option value="">Chọn sản phẩm</option>'
                + tracked.map((product) => `<option value="${escape(product.id)}">${escape(product.name)} · Còn ${Number(product.stock_quantity)}</option>`).join("");
            if (tracked.some((product) => product.id === previous)) stockForm.elements.productId.value = previous;
        }
        one("[data-stock-products]").innerHTML = products.map((product) => `<article class="rounded-xl border border-[#e5dacb] p-3">
            <h3>${escape(product.name)}</h3><p class="mt-2 font-semibold">${Number(product.track_stock) ? `Tồn: ${Number(product.stock_quantity)}` : "Dịch vụ · Không theo dõi tồn"}</p>
            <p class="mt-1 text-xs text-stone-500">${Number(product.is_active) ? "Đang bán" : "Ngừng bán"}</p>
        </article>`).join("") || '<p class="text-sm">Chưa có sản phẩm. Thêm sản phẩm trước khi nhập kho.</p>';
        one("[data-stock-history]").innerHTML = result.movements.length ? `<table><thead><tr><th>Thời gian</th><th>Sản phẩm</th><th>Loại</th><th>Thay đổi</th><th>Tồn sau</th><th>Ghi chú / Mã đơn</th><th>Nhân viên</th></tr></thead><tbody>${result.movements.map((move) => `<tr>
            <td>${escape(dateTime(move.created_at))}</td><td>${escape(move.product_name)}</td><td>${move.movement_type === "IN" ? "Nhập kho" : move.movement_type === "RETURN" ? "Hoàn kho" : "Bán hàng"}</td>
            <td>${Number(move.quantity_change) > 0 ? "+" : ""}${Number(move.quantity_change)}</td><td>${Number(move.stock_after)}</td><td>${escape(move.note)}</td><td>${escape(move.created_by)}</td>
        </tr>`).join("")}</tbody></table>` : '<p class="text-sm">Chưa có biến động kho.</p>';
        one("[data-stock-page]").textContent = `Trang ${result.page} · ${result.count} biến động`;
        one("[data-stock-prev]").disabled = stockPage <= 1;
        one("[data-stock-next]").disabled = stockPage * 30 >= result.count;
    }
    async function load() {
        if (loading || busy) return;
        loading = true;
        status.textContent = "Đang tải…";
        try {
            if (inventoryMode) {
                await loadInventory();
            } else if (productMode || (!historyMode && can("sales.create"))) {
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
        const cancelled = sale.status === "CANCELLED";
        const pending = sale.status === "PENDING";
        const safeLink = typeof sale.checkout_url === "string" && /^https:\/\/pay\.payos\.vn\//.test(sale.checkout_url);
        detail.innerHTML = `<p class="text-lg font-semibold">${escape(sale.sale_code)}</p>
            <p class="text-sm font-semibold">Phòng: ${escape(sale.room_name_snapshot || "Không gắn phòng")}</p>
            ${sale.booking_code_snapshot ? `<p class="text-sm">Đơn đặt phòng: <strong>${escape(sale.booking_code_snapshot)}</strong></p>` : ""}
            <p class="text-sm">${escape(dateTime(sale.created_at))} · ${cancelled ? "Đã hủy" : paid ? (sale.completion_method === "GIFT" ? "Tặng khách" : "Đã hoàn thành") : "Chờ thanh toán"}</p>
            ${sale.items.map((item) => `<p class="flex justify-between gap-3 text-sm"><span>${escape(item.product_name)} × ${Number(item.quantity)}</span><strong>${cash(Number(item.unit_price) * Number(item.quantity))}</strong></p>`).join("")}
            <p class="text-sm">Theo bảng giá: ${cash(sale.listed_total)}</p>
            <p class="text-sm">Giá chốt trước giảm: ${cash(sale.subtotal || sale.listed_total)}</p>
            ${Number(sale.discount_percent) > 0 ? `<p class="text-sm">Giảm ${Number(sale.discount_percent)}%: ${cash(sale.discount_amount)}</p>` : ""}
            <p class="text-xl font-semibold">Khách cần trả: ${cash(sale.total)}</p>
            ${cancelled ? `<p class="text-sm text-red-800">Đã hủy và hoàn kho · ${escape(sale.cancel_reason)} · ${escape(dateTime(sale.cancelled_at))}</p>` : ""}
            ${sale.completion_method === "MANUAL_TRANSFER" ? `<p class="text-sm">Xác nhận chuyển khoản trước: ${escape(sale.completion_note)} · Nhân viên ${escape(sale.completed_by)}</p>` : ""}
            ${paid ? '<p class="text-sm text-emerald-800">Đã ghi nhận hoàn thành · ' + escape(dateTime(sale.paid_at)) + '</p>' : pending ? '<p class="text-sm text-stone-600">Đơn chưa tính doanh thu. Mở mã QR trên payOS để khách chuyển khoản.</p>' : ""}
            ${warning ? `<p class="text-sm text-red-800" role="status">${escape(warning)}</p>` : ""}
            <div class="flex flex-wrap gap-2">
                ${pending && safeLink ? `<a class="timeline-button timeline-primary" href="${escape(sale.checkout_url)}" target="_blank" rel="noopener noreferrer">Mở mã QR payOS ↗</a>` : ""}
                ${pending && can("sales.create") ? `<button class="timeline-button" type="button" data-sale-action="${safeLink ? "check" : "payment"}">${safeLink ? "Kiểm tra thanh toán" : "Tạo lại QR"}</button>` : ""}
            </div>
            ${pending && (can("sales.cancel") || can("sales.complete")) ? `<label class="grid gap-1 text-sm">Lý do hủy / thông tin đối soát chuyển khoản<textarea class="timeline-input" maxlength="255" rows="2" data-sale-note></textarea></label><div class="flex flex-wrap gap-2">
                ${can("sales.cancel") ? '<button class="timeline-button" type="button" data-sale-action="cancel">Hủy đơn & hoàn kho</button>' : ""}
                ${can("sales.complete") ? '<button class="timeline-button timeline-primary" type="button" data-sale-action="complete">Xác nhận đã chuyển khoản trước</button>' : ""}
            </div>` : ""}
            <p class="text-sm text-stone-600" data-sale-message role="status"></p>`;
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
        const data = { id: form.elements.id.value, name: form.elements.name.value.trim(), price: Number(form.elements.price.value), isActive: form.elements.isActive.checked, trackStock: form.elements.trackStock.checked };
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
    stockForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || loading || !stockForm.reportValidity()) return;
        const data = { productId: stockForm.elements.productId.value, quantity: Number(stockForm.elements.quantity.value), note: stockForm.elements.note.value.trim() };
        const signature = JSON.stringify(data);
        if (stockSignature !== signature || !stockRequestId) {
            stockRequestId = crypto.randomUUID().replaceAll("-", "");
            stockSignature = signature;
        }
        busy = true;
        const submit = stockForm.querySelector('[type="submit"]');
        submit.disabled = true;
        let saved = false;
        try {
            await request("inventory", "POST", { ...data, requestId: stockRequestId });
            saved = true;
            stockRequestId = "";
            stockSignature = "";
            stockForm.reset();
            stockPage = 1;
        } catch (error) {
            status.textContent = error.message;
        } finally {
            busy = false;
            submit.disabled = false;
        }
        if (saved) {
            await load();
            status.textContent = "Đã nhập kho và lưu lịch sử.";
        }
    });
    one("[data-retail-search]")?.addEventListener("input", renderProducts);
    one("[data-sales-filter-form]")?.addEventListener("submit", (event) => {
        event.preventDefault();
        if (!busy && !loading && event.target.reportValidity()) { page = 1; historyPage(); }
    });
    one("[data-cart-total]")?.addEventListener("input", renderPayable);
    one("[data-cart-discount]")?.addEventListener("input", renderPayable);
    one("[data-retail-refresh]").addEventListener("click", load);
    one("[data-product-reset]")?.addEventListener("click", () => { if (!busy) resetForm(); });
    root.addEventListener("change", (event) => {
        const input = event.target.closest("[data-quantity]");
        if (!input || busy) return;
        const quantity = Number(input.value);
        const max = 999;
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > max) {
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
            const max = 999;
            if (max <= 0 || (current?.quantity || 0) >= max) {
                status.textContent = "Số lượng tối đa 999 cho mỗi sản phẩm.";
                return;
            }
            cart.set(product.id, { product, quantity: (current?.quantity || 0) + 1 });
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
            form.elements.trackStock.checked = !!Number(product.track_stock);
            form.elements.name.focus();
        } else if (open) {
            trigger = open;
            const active = ++detailVersion;
            detail.innerHTML = '<p role="status">Đang tải đơn…</p>';
            dialog.showModal();
            try {
                const result = await request("sales/" + open.dataset.saleOpen);
                if (active === detailVersion && dialog.open) {
                    showSale(result.sale);
                    if (open.dataset.saleIntent) one("[data-sale-note]")?.focus();
                }
            } catch (error) {
                if (active === detailVersion && dialog.open) detail.textContent = error.message;
            }
        } else if (event.target.closest("[data-sale-create]")) {
            if (!cart.size || !one("[data-cart-total]").reportValidity() || !one("[data-cart-discount]").reportValidity()) return;
            const roomId = one("[data-sale-room]").value || "";
            const bookingId = saleRooms.find((room) => room.id === roomId)?.activeBooking?.id || "";
            const data = { discountPercent: Number(one("[data-cart-discount]").value || 0), roomId, bookingId, items: [...cart.values()].map(({ product, quantity }) => ({ productId: product.id, quantity })), total: Number(one("[data-cart-total]").value) };
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
                for (const entry of cart.values()) {
                    if (Number(entry.product.track_stock)) entry.product.stock_quantity = Number(entry.product.stock_quantity) - entry.quantity;
                }
                cart.clear();
                one("[data-cart-discount]").value = "0";
                renderProducts();
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
            let payload = {};
            if (["cancel", "complete"].includes(action.dataset.saleAction)) {
                const note = one("[data-sale-note]")?.value.trim() || "";
                if (!note) { one("[data-sale-message]").textContent = "Nhập lý do / thông tin đối soát trước khi xác nhận."; return; }
                const accepted = await LangUI["confirm"]({
                    title: action.dataset.saleAction === "cancel" ? "Hủy đơn và hoàn kho?" : "Xác nhận khách đã chuyển khoản?",
                    message: action.dataset.saleAction === "cancel" ? "Chỉ hủy đơn chưa thanh toán. QR sẽ được đóng và hàng được hoàn lại kho." : "Chỉ xác nhận sau khi đã kiểm tra tiền vào ngân hàng. Đơn sẽ được tính doanh thu và lưu người xác nhận.",
                    confirmLabel: "Xác nhận", cancelLabel: "Quay lại",
                });
                if (!accepted) return;
                payload = { note };
            }
            busy = true;
            action.disabled = true;
            try {
                const result = await request("sales/" + sale.id + "/" + action.dataset.saleAction, "POST", payload);
                showSale(result.sale);
                await loadHistory();
            } catch (error) {
                one("[data-sale-message]").textContent = error.message;
            } finally {
                busy = false;
                if (action.isConnected) action.disabled = false;
            }
        } else if (event.target.closest("[data-stock-prev]") || event.target.closest("[data-stock-next]")) {
            stockPage = Math.max(1, stockPage + (event.target.closest("[data-stock-prev]") ? -1 : 1));
            await load();
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
