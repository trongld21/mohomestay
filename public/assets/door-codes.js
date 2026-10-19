(() => {
    const root = document.querySelector('[data-door-codes]');
    if (!root) return;
    const form = root.querySelector('[data-door-form]');
    const list = root.querySelector('[data-door-list]');
    const notice = root.querySelector('[data-door-notice]');
    const errorBox = root.querySelector('[data-door-error]');
    const title = root.querySelector('#door-form-title');
    const save = root.querySelector('[data-door-save]');
    const refresh = root.querySelector('[data-door-refresh]');
    const labels = { ACTIVE: 'Đang dùng', DISABLED: 'Đã tắt', SCHEDULED: 'Chưa đến hạn', EXPIRED: 'Hết hạn' };
    const periods = { DAY: 'Ngày', WEEK: 'Tuần', MONTH: 'Tháng' };
    const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    const localTime = utc => new Date(utc.replace(' ', 'T') + 'Z').toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh', hour12: false });
    const inputTime = utc => {
        const date = new Date(utc.replace(' ', 'T') + 'Z');
        return new Date(date.getTime() + 7 * 3600000).toISOString().slice(0, 16);
    };
    let codes = [];
    let loading = false;
    async function request(url, method = 'GET', body) {
        const response = await fetch(url, {
            method,
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.LANG_HOME.csrf },
            ...(body ? { body: JSON.stringify(body) } : {})
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Không thể kết nối máy chủ.');
        return data;
    }
    function render() {
        const now = Date.now();
        list.innerHTML = codes.length ? codes.map(row => {
            const start = new Date(row.starts_at.replace(' ', 'T') + 'Z').getTime();
            const end = new Date(row.ends_at.replace(' ', 'T') + 'Z').getTime();
            const status = now >= end ? 'EXPIRED' : row.status === 'DISABLED' ? 'DISABLED' : now < start ? 'SCHEDULED' : 'ACTIVE';
            const color = status === 'ACTIVE' ? 'bg-green-100 text-green-900' : status === 'EXPIRED' ? 'bg-stone-100 text-stone-700' : 'bg-amber-100 text-amber-950';
            return `<article class="rounded-2xl border border-stone-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="break-all font-mono text-xl font-semibold">${escape(row.code)}</h3><span class="rounded-full px-3 py-1 text-sm font-medium ${color}">${labels[status]}</span></div>
                <p class="mt-2 text-sm text-stone-600">Mã theo ${periods[row.period].toLowerCase()} · ${row.status === 'ACTIVE' ? 'Đã bật' : 'Đã tắt'}</p>
                <p class="mt-3 text-sm">Từ ${escape(localTime(row.starts_at))}<br>Đến ${escape(localTime(row.ends_at))}</p>
                <button type="button" data-door-edit="${escape(row.id)}" class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-stone-300 px-4 py-2 font-medium hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">Sửa mã / trạng thái</button>
            </article>`;
        }).join('') : '<p class="rounded-xl border border-dashed border-stone-300 p-6 text-stone-600">Chưa có mã mở cửa. Thêm mã đầu tiên bằng biểu mẫu bên cạnh.</p>';
    }
    async function load() {
        if (loading) return;
        loading = true;
        refresh.disabled = true;
        try {
            const data = await request('/api/admin/door-codes');
            codes = data.codes;
            render();
            notice.textContent = `${codes.length} mã · Giờ Việt Nam · Trạng thái tự cập nhật theo thời gian.`;
        } catch (error) {
            notice.textContent = error.message;
        } finally {
            loading = false;
            refresh.disabled = false;
        }
    }
    function reset() {
        form.reset();
        form.elements.id.value = '';
        title.textContent = 'Thêm mã mở cửa';
        errorBox.hidden = true;
    }
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-door-edit]');
        if (!button) return;
        const row = codes.find(item => item.id === button.dataset.doorEdit);
        if (!row) return;
        for (const key of ['id', 'code', 'period', 'status']) {
            form.elements[key].value = row[key];
            if (form.elements[key].tagName === 'SELECT') {
                form.elements[key].dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        form.elements.startsAt.value = inputTime(row.starts_at);
        form.elements.endsAt.value = inputTime(row.ends_at);
        title.textContent = 'Chỉnh sửa mã mở cửa';
        errorBox.hidden = true;
        form.elements.code.focus();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const body = Object.fromEntries(new FormData(form));
        const id = body.id;
        delete body.id;
        save.disabled = true;
        root.querySelector('[data-door-reset]').disabled = true;
        errorBox.hidden = true;
        try {
            await request('/api/admin/door-codes' + (id ? '/' + id : ''), id ? 'PATCH' : 'POST', body);
            reset();
            await load();
            window.LangUI.toast({ type: 'success', title: 'Đã lưu mã mở cửa', message: 'Thời gian và trạng thái đã được cập nhật.' });
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
        } finally {
            save.disabled = false;
            root.querySelector('[data-door-reset]').disabled = false;
        }
    });
    root.querySelector('[data-door-reset]').addEventListener('click', reset);
    refresh.addEventListener('click', load);
    // Update only the status text, preserving keyboard focus on edit controls.
    setInterval(() => {
        const now = Date.now();
        list.querySelectorAll('article').forEach((article, index) => {
            const row = codes[index];
            if (!row) return;
            const start = new Date(row.starts_at.replace(' ', 'T') + 'Z').getTime();
            const end = new Date(row.ends_at.replace(' ', 'T') + 'Z').getTime();
            const status = now >= end ? 'EXPIRED' : row.status === 'DISABLED' ? 'DISABLED' : now < start ? 'SCHEDULED' : 'ACTIVE';
            const badge = article.querySelector('span');
            badge.textContent = labels[status];
            badge.className = 'rounded-full px-3 py-1 text-sm font-medium ' + (status === 'ACTIVE' ? 'bg-green-100 text-green-900' : status === 'EXPIRED' ? 'bg-stone-100 text-stone-700' : 'bg-amber-100 text-amber-950');
        });
    }, 1000);
    load();
})();
