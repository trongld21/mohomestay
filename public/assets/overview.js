(() => {
    "use strict";

    const root = document.querySelector("[data-overview]");
    if (!root) return;
    const form = root.querySelector("[data-overview-filter]");
    const preset = root.querySelector("[data-overview-preset]");
    const from = form.elements.from;
    const to = form.elements.to;
    const validate = () => {
        to.setCustomValidity("");
        if (!from.value || !to.value) return;
        const days = (Date.parse(to.value + "T00:00:00Z") - Date.parse(from.value + "T00:00:00Z")) / 86400000;
        if (days < 0 || days > 92) to.setCustomValidity("Chọn khoảng ngày hợp lệ, tối đa 93 ngày.");
    };
    [from, to].forEach((input) => input.addEventListener("input", () => {
        preset.value = "";
        validate();
    }));
    preset.addEventListener("change", () => {
        if (!preset.value) return;
        [from.value, to.value] = preset.value.split("/");
        validate();
        form.requestSubmit();
    });
    form.addEventListener("submit", (event) => {
        validate();
        if (!form.reportValidity()) event.preventDefault();
    });
    const refresh = root.querySelector("[data-overview-refresh]");
    refresh.hidden = false;
    refresh.addEventListener("click", () => window.location.reload());
    root.querySelectorAll("[data-overview-chart]").forEach((chart) => {
        const tabs = [...chart.querySelectorAll("[data-chart-period]")];
        tabs.forEach((tab) => {
            tab.hidden = false;
            tab.addEventListener("click", () => {
                tabs.forEach((item) => item.setAttribute("aria-pressed", String(item === tab)));
                chart.querySelectorAll("[data-chart-series]").forEach((series) => {
                    series.hidden = series.dataset.chartSeries !== tab.dataset.chartPeriod;
                });
            });
        });
    });
    const exporter = root.querySelector("[data-overview-export]");
    if (!exporter) return;
    const data = JSON.parse(root.querySelector("[data-overview-data]").textContent);
    const types = { room: "Tiền phòng", retail: "Bán hàng", surcharge: "Phụ thu", expense: "Phiếu chi" };
    const methods = { cash: "Tiền mặt", transfer: "Chuyển khoản", other: "Khác" };
    const cell = (value) => {
        let text = String(value ?? "");
        if (/^[\s]*[=+\-@]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };
    exporter.hidden = false;
    exporter.title = "Tải file CSV UTF-8, có thể mở bằng Excel";
    exporter.addEventListener("click", () => {
        const rows = [["Thời gian (Việt Nam)", "Loại", "Mã giao dịch", "Phòng / nội dung", "Số tiền (VND)", "Thanh toán"]];
        data.transactions.forEach((item) => rows.push([
            item.local_time, types[item.type], item.code, item.room, item.amount, methods[item.payment] || "Chi tiền",
        ]));
        const csv = "\uFEFF" + rows.map((row) => row.map(cell).join(",")).join("\r\n");
        const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
        const link = document.createElement("a");
        link.href = url;
        link.download = `mo-tong-quan-${data.from}-${data.to}.csv`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
})();
