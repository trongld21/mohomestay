(() => {
    "use strict";

    document.querySelectorAll("[data-room-quote-hold]").forEach((root) => {
        const select = root.querySelector("[data-hold-package]");
        const date = root.querySelector("[data-hold-date]");
        const time = root.querySelector("[data-hold-time]");
        const button = root.querySelector("[data-hold-create]");
        const status = root.querySelector("[data-hold-status]");
        const room = window.LANG_HOME.rooms.find((r) => r.id === root.dataset.roomId);
        const packageInfo = () => room?.packages.find((p) => p.id === select.value);
        function refresh() {
            const fixed = packageInfo()?.mode === "FIXED_TIME";
            root.querySelector("[data-hold-time-label]").hidden = fixed;
            if (fixed) time.value = packageInfo().checkInTime;
        }
        select.addEventListener("change", refresh);
        refresh();
        button.addEventListener("click", async () => {
            if (!date.value || (!time.value && packageInfo()?.mode !== "FIXED_TIME")) {
                status.textContent = "Chọn đầy đủ gói, ngày và giờ nhận phòng.";
                return;
            }
            button.disabled = true;
            try {
                const response = await fetch("/api/admin/room-operations/hold", {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": LANG_HOME.csrf },
                    body: JSON.stringify({
                        roomId: root.dataset.roomId, packageId: select.value,
                        date: date.value, time: time.value,
                    }),
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || "Chưa giữ được căn.");
                const expires = new Date(result.hold.expires_at.replace(" ", "T") + "Z");
                status.textContent = "Đã khóa khung giờ. Tự mở lúc " + expires.toLocaleString("vi-VN", { timeZone: "Asia/Ho_Chi_Minh" }) + ". Có thể mở lại ở bảng lịch bên dưới.";
                document.dispatchEvent(new Event("mo:inventory-changed"));
            } catch (error) {
                status.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
    });
})();
