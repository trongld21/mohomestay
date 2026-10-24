(() => {
    "use strict";

    document.querySelectorAll("[data-room-quote-hold]").forEach((root) => {
        const date = root.querySelector("[data-hold-date]");
        const time = root.querySelector("[data-hold-time]");
        const button = root.querySelector("[data-hold-create]");
        const status = root.querySelector("[data-hold-status]");
        button.addEventListener("click", async () => {
            if (!date.reportValidity() || !time.reportValidity()) return;
            button.disabled = true;
            try {
                const response = await fetch("/api/admin/room-operations/hold", {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
                    body: JSON.stringify({
                        roomId: root.dataset.roomId,
                        date: date.value,
                        time: time.value,
                    }),
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || "Chưa khóa được phòng.");
                const expires = new Date(result.hold.expires_at.replace(" ", "T") + "Z");
                const starts = new Date(result.hold.starts_at.replace(" ", "T") + "Z");
                const format = (value) => value.toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" });
                status.textContent = "Đã giữ phòng từ " + format(starts) + " đến " + format(expires)
                    + ". Tự mở khi hết hạn; có thể mở sớm ở bảng lịch bên dưới.";
                document.dispatchEvent(new Event("mo:inventory-changed"));
            } catch (error) {
                status.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
    });
})();
