(() => {
    "use strict";

    const root = document.querySelector("[data-home-availability]");
    if (!root) return;
    const grid = root.querySelector("[data-public-room-grid]");
    const status = root.querySelector("[data-home-availability-status]");
    const cards = [...grid.querySelectorAll("[data-room-card-id]")];
    let timer;
    let loading = false;

    async function refresh() {
        clearTimeout(timer);
        if (loading || document.hidden) return;
        loading = true;
        try {
            const response = await fetch("/api/rooms/available-now", {
                cache: "no-store",
                signal: AbortSignal.timeout(10000),
            });
            const data = await response.json();
            if (!response.ok || !Array.isArray(data.roomIds)) throw new Error("availability");
            const available = new Set(data.roomIds);
            let visible = 0;
            for (const card of cards) {
                card.hidden = !available.has(card.dataset.roomCardId);
                if (!card.hidden) visible++;
            }
            status.textContent = visible
                ? ""
                : "Hiện chưa có phòng trống. Bạn có thể xem lịch để chọn khung giờ khác.";
            status.hidden = visible > 0;
            grid.hidden = visible === 0;
        } catch {
            grid.hidden = true;
            status.hidden = false;
            status.textContent = "Chưa kiểm tra được phòng trống. Mơ sẽ tự thử lại; bạn cũng có thể xem lịch phòng.";
        } finally {
            grid.setAttribute("aria-busy", "false");
            loading = false;
            if (!document.hidden) timer = setTimeout(refresh, 15000);
        }
    }

    document.addEventListener("visibilitychange", () => {
        if (document.hidden) clearTimeout(timer);
        else refresh();
    });
    window.addEventListener("focus", refresh);
    refresh();
})();
