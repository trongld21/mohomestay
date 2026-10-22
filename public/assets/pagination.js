((root) => {
    function pages(current, total) {
        const visible = new Set([1, total, current - 1, current, current + 1]);
        if (current <= 3) [2, 3, 4].forEach((page) => visible.add(page));
        if (current >= total - 2)
            [total - 3, total - 2, total - 1].forEach((page) => visible.add(page));
        const result = [];
        [...visible]
            .filter((page) => page >= 1 && page <= total)
            .sort((a, b) => a - b)
            .forEach((page) => {
                const previous = result.at(-1);
                if (previous && page - previous > 1) {
                    result.push(page - previous === 2 ? previous + 1 : "…");
                }
                result.push(page);
            });
        return result;
    }
    function windowFor(items, requested, size = 10) {
        const total = Math.max(1, Math.ceil(items.length / size));
        const page = Math.min(total, Math.max(1, Math.trunc(Number(requested)) || 1));
        const start = (page - 1) * size;
        return {
            items: items.slice(start, start + size),
            page,
            total,
            start: items.length ? start + 1 : 0,
            end: Math.min(start + size, items.length),
            count: items.length,
        };
    }
    function create(name, onChange, size = 10) {
        let current = 1;
        document.addEventListener("click", (event) => {
            const button = event.target.closest("button[data-pagination]");
            if (!button || button.dataset.pagination !== name || button.disabled) return;
            current = Number(button.dataset.page);
            onChange();
            document.querySelector(`[data-pagination="${name}"][aria-current="page"]`)?.focus();
        });
        return {
            reset() {
                current = 1;
            },
            slice(items) {
                const view = windowFor(items, current, size);
                current = view.page;
                return view;
            },
            render(view, noun) {
                const button = (page, label, text, disabled = false, active = false) =>
                    `<button type="button" data-pagination="${name}" data-page="${page}" aria-label="${label}" ${disabled ? "disabled" : ""} ${active ? 'aria-current="page"' : ""}>${text}</button>`;
                return `<div class="admin-pagination"><span role="status">Hiển thị ${view.start}–${view.end} / ${view.count} ${noun}</span><nav aria-label="Phân trang ${noun}">${button(view.page - 1, "Trang trước", "‹", view.page === 1)}${pages(
                    view.page,
                    view.total,
                )
                    .map((page) =>
                        page === "…"
                            ? '<span aria-hidden="true">…</span>'
                            : button(page, "Trang " + page, page, false, page === view.page),
                    )
                    .join(
                        "",
                    )}${button(view.page + 1, "Trang sau", "›", view.page === view.total)}</nav></div>`;
            },
        };
    }
    const helpers = { pages, windowFor, create };
    if (typeof module !== "undefined" && module.exports) module.exports = helpers;
    else root.MoPagination = helpers;
})(globalThis);
