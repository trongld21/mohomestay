(() => {
    "use strict";
    const root = document.querySelector("[data-booking-success]");
    if (!root) return;
    const out = root.querySelector("[data-success-content]");
    const title = root.querySelector("[data-success-title]");
    const intro = root.querySelector("[data-success-intro]");
    const code = root.dataset.code;
    const key = "mo-success-" + code;
    let token = location.hash.slice(1);
    try {
        if (token) sessionStorage.setItem(key, token);
        else token = sessionStorage.getItem(key) || "";
    } catch {}
    if (location.hash) history.replaceState(null, "", location.pathname);
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[char]);
    const cash = (value) => new Intl.NumberFormat("vi-VN").format(value) + "đ";
    const date = (value) => new Intl.DateTimeFormat("vi-VN", {
        timeZone: "Asia/Ho_Chi_Minh", dateStyle: "short", timeStyle: "short",
    }).format(new Date(value));
    const safeUrl = (value, local = false) => {
        const url = String(value || "").trim();
        if (local && /^\/(?!\/)[^\\\s]*$/.test(url)) return escape(url);
        try {
            const parsed = new URL(url);
            return parsed.protocol === "https:" ? escape(parsed.href) : "";
        } catch {
            return "";
        }
    };

    function arrivalGuide(arrival) {
        const info = arrival || {};
        const guideUrl = safeUrl(info.guideUrl);
        const rulesUrl = safeUrl(info.rulesUrl);
        return `
            <section class="booking-success-next" aria-labelledby="arrival-heading">
                <span class="text-xs font-semibold tracking-widest text-[#79543e]">CHUẨN BỊ CHO CUỘC HẸN</span>
                <h2 id="arrival-heading" class="mt-2">Hướng dẫn tự check-in</h2>
                <ol class="booking-arrival-steps">
                    <li>
                        <span class="booking-arrival-number" aria-hidden="true">1</span>
                        <div><h3>Địa chỉ Mơ Home</h3>
                            <p>82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ.</p>
                            <a href="https://www.google.com/maps/search/?api=1&query=82%20B18%20KDC%2091B%20Ninh%20Kieu%20Can%20Tho" target="_blank" rel="noopener noreferrer">Xem trên Google Maps ↗</a>
                        </div>
                    </li>
                    <li>
                        <span class="booking-arrival-number" aria-hidden="true">2</span>
                        <div><h3>Nhận phòng & mã mở cổng</h3>
                            <p>Đến đúng khung giờ đã đặt. Chuẩn bị mã đặt phòng và nhắn Mơ qua Zalo để được hướng dẫn vào phòng.</p>
                            ${guideUrl ? `<a href="${guideUrl}" target="_blank" rel="noopener noreferrer">Xem hướng dẫn nhận phòng ↗</a>` : ""}

                        </div>
                    </li>
                    <li>
                        <span class="booking-arrival-number" aria-hidden="true">3</span>
                        <div><h3>Nội quy lưu trú</h3>
                            ${info.rules ? `<p class="whitespace-pre-line">${escape(info.rules)}</p>`
                                : "<p>Vui lòng liên hệ Mơ để nhận nội quy và các lưu ý trước khi đến.</p>"}
                            ${rulesUrl ? `<a href="${rulesUrl}" target="_blank" rel="noopener noreferrer">Xem nội quy đầy đủ ↗</a>` : ""}
                        </div>
                    </li>

                </ol>
                <div class="mt-6 border-t border-[#e8ded0] pt-4">
                    <p>Cần hỗ trợ hoặc thay đổi lịch? Mơ luôn sẵn sàng giúp bạn.</p>
                    <a href="https://zalo.me/0357907153" target="_blank" rel="noopener noreferrer">Nhắn Mơ qua Zalo ↗</a>
                    <a href="tel:0357907153">Gọi 0357 907 153</a>
                </div>
            </section>`;
    }

    function arrivalCredentials(arrival) {
        const info = arrival || {};
        const doorCodes = info.doorCodes || [];
        return `<section class="booking-success-credentials" aria-label="Mã cổng và Wi-Fi">
            <h3 class="text-sm font-semibold">Mã mở cổng của bạn</h3>
            ${doorCodes.length ? doorCodes.map((door) => {
                const code = String(door.code ?? "").trim();
                return `<p class="booking-gate-password">Mật khẩu cổng: ${escape(code)}${code.endsWith("#") ? "" : "#"}</p>`;
            }).join("")
                : `<p class="mt-2 font-medium text-[#79543e]">${info.isTest
                    ? "Chưa có mã cổng phù hợp. Kiểm tra mã đang Bật và thời gian hiệu lực trong trang quản trị."
                    : "Mã mở cổng chưa sẵn sàng. Vui lòng liên hệ Mơ trước khi đến."}</p>`}
            ${doorCodes.length ? '<p class="mt-2 text-sm text-[#79543e]">Mã dùng được nhiều lần trong thời gian hiệu lực, bạn có thể ra ngoài rồi vào lại.</p>' : ""}
            <div class="mt-3 border-t border-[#e8ded0] pt-2">
                <h3 class="mb-1 text-sm font-semibold">Wi-Fi tại Mơ</h3>
            ${info.wifiName ? `<dl class="booking-arrival-wifi">
                <div><dt>Tên mạng</dt><dd>${escape(info.wifiName)}</dd></div>
                <div><dt>Mật khẩu</dt><dd>${info.wifiPassword ? escape(info.wifiPassword) : "Liên hệ Mơ để nhận mật khẩu."}</dd></div>
            </dl>` : "<p>Nhắn Mơ để nhận tên mạng và mật khẩu Wi-Fi khi nhận phòng.</p>"}
            </div>
        </section>`;
    }

    async function load() {
        try {
            if (!/^[a-f0-9]{64}$/.test(token)) throw new Error("Vui lòng tra cứu bằng mã đặt phòng và số điện thoại để xem thông tin đơn.");
            const response = await fetch("/api/bookings/lookup", {
                method: "POST", headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ code, token }),
            });
            const booking = await response.json();
            if (!response.ok) throw new Error(booking.error || "Chưa tải được thông tin đặt phòng.");
            if (!["CONFIRMED", "CHECKED_IN", "CHECKED_OUT"].includes(booking.status)) {
                title.textContent = booking.status === "PENDING" ? "Đơn đang chờ thanh toán" : "Đặt phòng chưa được xác nhận";
                intro.textContent = "Kiểm tra trạng thái đơn trước khi đến Mơ.";
                out.innerHTML = `<div class="booking-success-actions"><a class="button" href="/payment?code=${encodeURIComponent(code)}#${encodeURIComponent(token)}">Xem trạng thái đơn</a><a class="button outline" href="/bookings#tra-cuu">Tra cứu đặt phòng</a></div>`;
                return;
            }
            title.textContent = "Hẹn gặp bạn tại Mơ!";
            root.querySelector("[data-success-mark]").hidden = false;
            intro.textContent = "Mơ đã xác nhận thông tin đặt phòng của bạn.";
            const image = safeUrl(booking.roomImage, true);
            out.innerHTML = `
                <article class="booking-success-ticket">
                    <header><div><span class="text-sm text-[#736b61]">Mã đặt phòng</span><strong>${escape(booking.bookingCode)}</strong></div><button type="button" data-copy-booking>Sao chép mã</button></header>
                    <div class="booking-success-room">
                        ${image ? `<img data-success-room-image src="${image}" alt="Không gian phòng ${escape(booking.room)}" width="320" height="240">` : ""}
                        <div class="min-w-0">
                            ${booking.arrival?.isTest ? '<span class="mb-2 inline-block text-xs font-semibold tracking-widest text-[#946529]">ĐƠN THỬ</span>' : ""}
                            <span class="block text-xs text-[#736b61]">Không gian của bạn</span>
                            <h2>${escape(booking.room)}</h2>
                            <p>${booking.guests ? `${escape(booking.guests)} khách` : ""}</p>
                        </div>
                    </div>
                    <div class="booking-success-details">
                        <div><span>Nhận phòng</span><strong>${escape(date(booking.checkIn))}</strong></div>
                        <div><span>Trả phòng</span><strong>${escape(date(booking.checkOut))}</strong></div>
                        <div><span>Thanh toán</span><strong class="${booking.paymentStatus === "PAID" ? "text-[#47694b]" : "text-[#946529]"}">${booking.paymentStatus === "PAID" ? "Đã thanh toán" : "Chưa thanh toán"}</strong></div>
                        <div class="booking-success-total"><span>Tổng tiền</span><strong>${cash(booking.total)}</strong></div>
                    </div>
                    ${arrivalCredentials(booking.arrival)}
                </article>
                ${arrivalGuide(booking.arrival)}
                <p data-success-copy-status class="text-center text-sm text-[#79543e]" role="status"></p>
                <div class="booking-success-actions"><a class="button" href="/">Về trang chủ</a><a class="button outline" href="/bookings#tra-cuu">Tra cứu đặt phòng</a></div>`;
            const roomImage = out.querySelector("[data-success-room-image]");
            if (roomImage) roomImage.onerror = () => { roomImage.hidden = true; };
            out.querySelector("[data-copy-booking]").onclick = async () => {
                const status = out.querySelector("[data-success-copy-status]");
                try {
                    await navigator.clipboard.writeText(booking.bookingCode);
                    status.textContent = "Đã sao chép mã đặt phòng.";
                } catch {
                    status.textContent = "Bạn có thể chọn và sao chép mã ở đầu thông tin đặt phòng.";
                }
            };
        } catch (error) {
            title.textContent = "Kiểm tra thông tin đặt phòng";
            intro.textContent = error.message;
            out.innerHTML = '<div class="booking-success-actions"><a class="button" href="/bookings#tra-cuu">Tra cứu đặt phòng</a><a class="button outline" href="tel:0357907153">Gọi Mơ hỗ trợ</a></div>';
        } finally {
            out.setAttribute("aria-busy", "false");
        }
    }
    load();
})();
