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
            for (const card of cards) {
                const badge = card.querySelector("[data-room-availability]");
                if (!badge) continue;
                const isAvailable = available.has(card.dataset.roomCardId);
                badge.dataset.state = isAvailable ? "available" : "unavailable";
                badge.textContent = isAvailable ? "Còn phòng" : "Hết phòng";
            }
            status.textContent = "";
            status.hidden = true;
        } catch {
            for (const card of cards) {
                const badge = card.querySelector("[data-room-availability]");
                if (!badge) continue;
                badge.dataset.state = "unknown";
                badge.textContent = "Chưa rõ tình trạng";
            }
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
