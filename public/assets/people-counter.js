(() => {
    'use strict';
    const root = document.querySelector('[data-people-counter]');
    if (!root) return;
    const find = name => root.querySelector(`[data-counter-${name}]`);
    const form = find('filter');
    const numbers = new Intl.NumberFormat('vi-VN');
    const dates = new Intl.DateTimeFormat('vi-VN', {timeZone: 'Asia/Ho_Chi_Minh', dateStyle: 'short', timeStyle: 'medium'});
    let filter = Object.fromEntries(new FormData(form));
    let page = 1;
    let pages = 1;
    let request;
    let loading = false;
    let previewPaused = false;
    let previewLoading = false;
    let previewCamera = form.elements.cameraId.value;
    let previewLastAt = null;
    let sessionSnapshot = null;
    const video = find('video');
    let videoReady = false;
    let videoPlayer = null;
    let videoRetry = null;
    const overlayCanvas = find('overlay');
    let detectionFrames = [];
    let detectionLine = null;
    let overlayAnimation = null;

    function drawOverlay() {
        if (!videoReady || previewPaused || document.hidden) return;
        const at = videoPlayer?.playingDate?.getTime()
            ?? (typeof video.getStartDate === 'function' ? video.getStartDate().getTime() + video.currentTime * 1000 : NaN);
        const frame = CounterVideoOverlay.nearestFrame(detectionFrames, at);
        CounterVideoOverlay.draw(overlayCanvas, video, detectionLine, frame);
        overlayAnimation = requestAnimationFrame(drawOverlay);
    }

    function rememberOverlay(overlay) {
        if (!overlay || !Number.isFinite(overlay.atMs) || !Array.isArray(overlay.line) || !Array.isArray(overlay.boxes)) return;
        detectionLine = overlay.line;
        if (!detectionFrames.some(frame => frame.atMs === overlay.atMs)) detectionFrames.push(overlay);
        detectionFrames = detectionFrames.filter(frame => frame.atMs > Date.now() - 45000).slice(-150);
    }

    function stopVideo() {
        cancelAnimationFrame(overlayAnimation);
        overlayCanvas.classList.add('hidden');
        clearTimeout(videoRetry);
        videoPlayer?.destroy();
        videoPlayer = null;
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.classList.add('hidden');
        videoReady = false;
    }

    function startVideo() {
        if (root.dataset.counterVideoEnabled !== '1' || previewPaused || document.hidden) return;
        stopVideo();
        const source = `/api/admin/people-counter/video?${new URLSearchParams({cameraId: form.elements.cameraId.value})}`;
        if (window.Hls?.isSupported()) {
            videoPlayer = new Hls({
                liveSyncDuration: 2,
                liveMaxLatencyDuration: 5,
                liveSyncOnStallIncrease: 0.5,
                maxLiveSyncPlaybackRate: 1.2,
                backBufferLength: 4,
                maxBufferLength: 4,
                maxMaxBufferLength: 8,
                maxBufferSize: 4 * 1024 * 1024,
                capLevelToPlayerSize: true
            });
            videoPlayer.on(Hls.Events.ERROR, (_event, data) => {
                if (!data.fatal) return;
                stopVideo();
                loadPreview();
                videoRetry = setTimeout(startVideo, 10000);
            });
            videoPlayer.loadSource(source);
            videoPlayer.attachMedia(video);
            videoPlayer.on(Hls.Events.MANIFEST_PARSED, () => {
                video.play().catch(() => {
                    video.classList.remove('hidden');
                });
            });
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = source;
            video.play().catch(() => video.classList.remove('hidden'));
        }
    }

    video.addEventListener('playing', () => {
        videoReady = true;
        video.classList.remove('hidden');
        find('image').classList.add('hidden');
        find('image').removeAttribute('src');
        find('camera-empty').classList.add('hidden');
        find('frame-status').textContent = `Video trực tiếp · ${video.videoWidth} × ${video.videoHeight}`;
        overlayCanvas.classList.remove('hidden');
        cancelAnimationFrame(overlayAnimation);
        drawOverlay();
    });
    video.addEventListener('error', () => {
        if (!video.getAttribute('src')) return;
        stopVideo();
        loadPreview();
        videoRetry = setTimeout(startVideo, 10000);
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopVideo();
        else startVideo();
    });
    window.addEventListener('pagehide', stopVideo);
    const previewStates = {
        STARTING: ['Đang khởi động cầu nối hình ảnh trên PC. Vui lòng chờ.', 'Đang khởi động cầu nối'],
        MINIMIZED: ['Imou PC đang thu nhỏ. Mở lại cửa sổ Imou để tiếp tục hình ảnh; không cần tải lại trang.', 'Chờ mở lại Imou PC'],
        LAYOUT_CHANGED: ['Kích thước cửa sổ Imou đã thay đổi. Khôi phục bố cục đã chọn để tiếp tục lấy đúng vùng camera.', 'Chờ khôi phục bố cục Imou'],
        CAPTURE_STALLED: ['Chưa nhận được hình mới từ dịch vụ lấy video. Đang thử kết nối lại.', 'Chờ hình mới'],
        WINDOW_CLOSED: ['Cửa sổ Imou PC đã đóng. Mở Imou và khởi động lại cầu nối hình ảnh.', 'Imou PC đã đóng'],
        STOPPED: ['Dịch vụ lấy video đã dừng. Khởi động dịch vụ để tiếp tục xem trực tiếp.', 'Dịch vụ lấy video đã dừng'],
        BRIDGE_OFFLINE: ['Không nhận được tín hiệu từ dịch vụ lấy video. Trạng thái này chưa xác nhận camera mất mạng.', 'Dịch vụ lấy video đã dừng']
    };

    function clearPreview(message) {
        if (videoReady && !previewPaused) return;
        find('image').classList.add('hidden');
        find('image').removeAttribute('src');
        find('camera-empty').textContent = message;
        find('camera-empty').classList.remove('hidden');
    }

    function renderSession(session) {
        const started = new Date(session?.startedAt);
        const valid = session?.trial === true && /^[a-f0-9]{32}$/.test(session.id)
            && Number.isFinite(started.getTime()) && started.getTime() <= Date.now() + 60000
            && ['in', 'out'].every(key => Number.isSafeInteger(session[key]) && session[key] >= 0 && session[key] <= 10000000);
        for (const key of ['in', 'out', 'total']) {
            find(key).textContent = valid ? numbers.format(key === 'total' ? session.in + session.out : session[key]) : '—';
        }
        find('session-label').textContent = valid
            ? `Tổng phiên AI · từ ${dates.format(started)} · có thể gồm lượt trước khi lưu lịch sử`
            : 'Phiên AI thử nghiệm · chưa nhận số đếm trực tiếp';
        if (valid) {
            const snapshot = `${session.id}:${session.in}:${session.out}`;
            if (sessionSnapshot !== null && sessionSnapshot !== snapshot && !loading) load();
            sessionSnapshot = snapshot;
        }
    }

    async function loadPreview() {
        if (previewLoading || document.hidden) return;
        const camera = form.elements.cameraId.value;
        if (previewCamera !== camera) {
            previewCamera = camera;
            previewLastAt = null;
            renderSession(null);
            clearPreview('Đang chuyển camera…');
        }
        previewLoading = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const metadataOnly = previewPaused || videoReady;
            const response = await fetch(`/api/admin/people-counter/preview?${new URLSearchParams({cameraId: camera, metadataOnly: metadataOnly ? '1' : '0'})}`, {cache: 'no-store', signal: controller.signal});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Chưa tải được hình ảnh camera.');
            if (form.elements.cameraId.value !== camera) return;
            if (!data.available) {
                previewLastAt = null;
                renderSession(null);
                if (previewStates[data.state]) {
                    const [message, label] = previewStates[data.state];
                    clearPreview(message);
                    find('frame-status').textContent = label;
                    return;
                }
                clearPreview(data.state === 'STALE' ? 'Mất tín hiệu hình ảnh. Đang chờ khung hình mới từ camera.' : 'Chưa có hình ảnh camera. Cần cấp luồng video Imou và bật dịch vụ AI để xem đếm trực tiếp.');
                find('frame-status').textContent = data.state === 'STALE' ? 'Hình ảnh bị gián đoạn' : 'Chờ hình ảnh camera';
                return;
            }
            const at = new Date(data.frameAt);
            if (!Number.isFinite(at.getTime()) || Date.now() - at.getTime() > 30000 || at.getTime() - Date.now() > 60000
                || (!metadataOnly && (typeof data.jpeg !== 'string' || data.jpeg.length > 180000 || !/^[A-Za-z0-9+/=]+$/.test(data.jpeg)))) {
                throw new Error('Khung hình không hợp lệ hoặc đã quá cũ.');
            }
            renderSession(data.session);
            rememberOverlay(data.overlay);
            previewLastAt = at.getTime();
            if (previewPaused || metadataOnly) return;
            find('image').src = `data:image/jpeg;base64,${data.jpeg}`;
            find('image').classList.remove('hidden');
            find('camera-empty').classList.add('hidden');
            find('frame-status').textContent = `Khung hình ${dates.format(at)}`;
            previewLastAt = at.getTime();
        } catch (error) {
            if (form.elements.cameraId.value === camera) renderSession(null);
            if (!previewPaused && form.elements.cameraId.value === camera) {
                clearPreview('Chưa tải được hình ảnh camera. Đang thử kết nối lại.');
                previewLastAt = null;
                find('frame-status').textContent = error.message;
            }
        } finally {
            clearTimeout(timeout);
            previewLoading = false;
        }
    }

    find('pause').addEventListener('click', () => {
        previewPaused = !previewPaused;
        find('pause').setAttribute('aria-pressed', String(previewPaused));
        find('pause').textContent = previewPaused ? 'Tiếp tục hình ảnh' : 'Tạm dừng hình ảnh';
        if (previewPaused) {
            stopVideo();
            clearPreview('Hình ảnh đã tạm dừng. Số lượt vẫn cập nhật khi dịch vụ AI đang chạy.');
            find('frame-status').textContent = 'Đã tạm dừng xem';
        } else {
            startVideo();
            loadPreview();
        }
    });
    form.elements.cameraId.addEventListener('change', () => {
        detectionFrames = [];
        detectionLine = null;
        stopVideo();
        startVideo();
        loadPreview();
    });
    setInterval(() => {
        if (previewLastAt && Date.now() - previewLastAt > 30000) {
            renderSession(null);
            if (!previewPaused) {
                clearPreview('Mất tín hiệu hình ảnh. Đang chờ khung hình mới từ camera.');
                find('frame-status').textContent = 'Hình ảnh bị gián đoạn';
            }
        }
        loadPreview();
    }, 333);

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function messageRow(message) {
        const row = document.createElement('tr');
        const cell = element('td', 'px-5 py-8 text-stone-600', message);
        cell.colSpan = 3;
        row.append(cell);
        find('events').replaceChildren(row);
    }

    function renderChart(hours) {
        const chart = element('div', 'flex w-full min-w-0 gap-0.5 sm:gap-1');
        const max = Math.max(1, ...hours.flatMap(hour => [hour.in, hour.out]));
        hours.forEach(hour => {
            const group = element('div', 'min-w-0 flex-1');
            const label = `${hour.hour} giờ: ${hour.in} lượt vào, ${hour.out} lượt ra`;
            group.setAttribute('role', 'img');
            group.setAttribute('aria-label', label);
            group.title = label;
            const bars = element('div', 'flex h-36 items-end justify-center gap-0.5 border-b border-stone-200');
            for (const direction of ['in', 'out']) {
                const bar = element('div', direction === 'in' ? 'w-1/3 max-w-2 rounded-t bg-emerald-600' : 'w-1/3 max-w-2 rounded-t bg-red-500');
                bar.style.height = `${hour[direction] / max * 100}%`;
                bars.append(bar);
            }
            const labelClass = 'mt-2 text-center text-[10px] text-stone-600' + (hour.hour % 3 === 0 ? '' : ' invisible sm:visible');
            group.append(bars, element('p', labelClass, String(hour.hour).padStart(2, '0')));
            chart.append(group);
        });
        find('chart').replaceChildren(chart);
    }

    function render(data) {
        const trial = data.mode === 'trial';
        find('report-title').textContent = trial ? 'Báo cáo AI · chưa đối soát · khoảng ngày đã chọn' : 'Báo cáo vận hành · khoảng ngày đã chọn';
        find('report-note').textContent = trial
            ? 'Lưu thời gian từng lượt từ khi bật ghi lịch sử. Tổng phiên cạnh camera gồm cả lượt cũ chưa có thời gian; số AI cần đối soát thực tế.'
            : 'Dữ liệu vận hành được lưu riêng; lượt AI thử nghiệm không cộng vào báo cáo này.';
        const states = {
            WAITING: 'Chưa có dữ liệu đếm trong báo cáo. Số tổng phiên cạnh camera có thể gồm lượt cũ chưa được lưu lịch sử.',
            RUNNING: 'Dịch vụ AI đang nhận video. Đối chiếu thử với lượt thực tế tại cửa để kiểm tra vị trí vạch và hướng đếm.',
            OFFLINE: 'Dịch vụ đếm đang mất video. Các lượt trong khoảng gián đoạn có thể không được ghi nhận.',
            ERROR: 'Dịch vụ AI báo lỗi. Kiểm tra kết nối và dịch vụ xử lý video.',
            STALE: 'Đã quá 90 giây chưa có tín hiệu mới. Số liệu có thể chưa đầy đủ.',
            DISABLED: 'Camera này đã tắt nhận dữ liệu. Lịch sử đã ghi nhận vẫn được giữ.'
        };
        const notice = states[data.camera.state] || 'Cần kiểm tra dịch vụ camera.';
        find('notice').textContent = notice + (data.camera.lastReceivedAt ? ` Tín hiệu gần nhất: ${dates.format(new Date(data.camera.lastReceivedAt))}.` : '');
        find('report-in').textContent = numbers.format(data.totals.in);
        find('report-out').textContent = numbers.format(data.totals.out);
        find('report-total').textContent = numbers.format(data.count);
        const select = form.elements.cameraId;
        if (select.options.length !== data.cameras.length || [...select.options].some((option, index) => option.value !== data.cameras[index]?.id || option.textContent !== data.cameras[index]?.name)) {
            select.replaceChildren(...data.cameras.map(camera => new Option(camera.name, camera.id)));
        }
        select.value = data.camera.id;
        renderChart(data.hours);
        const rows = data.events.map(event => {
            const row = element('tr', 'border-b border-stone-100');
            const direction = element('td', 'px-3 py-3 sm:px-5');
            direction.append(element('span', event.direction === 'IN'
                ? 'inline-block rounded-full bg-emerald-50 px-3 py-1 font-medium text-emerald-800'
                : 'inline-block rounded-full bg-red-50 px-3 py-1 font-medium text-red-800', event.direction === 'IN' ? '↘ Vào' : '↗ Ra'));
            row.append(
                element('td', 'whitespace-nowrap px-3 py-3 tabular-nums sm:px-5', dates.format(new Date(event.occurredAt))),
                direction,
                element('td', 'hidden break-words px-5 py-3 sm:table-cell', data.camera.name)
            );
            return row;
        });
        find('events').replaceChildren(...rows);
        if (!rows.length) messageRow(trial
            ? 'Chưa có lượt mới được lưu trong khoảng ngày này. Tổng phiên có thể gồm lượt cũ trước khi bật lịch sử.'
            : 'Chưa ghi nhận lượt qua cửa trong khoảng ngày này.');
        page = data.page;
        pages = data.pages;
        find('page').textContent = `Trang ${page}/${pages} · ${numbers.format(data.count)} lượt`;
        find('updated').textContent = `Cập nhật ${dates.format(new Date())} · tự làm mới 5 giây`;
        find('prev').disabled = page <= 1;
        find('next').disabled = page >= pages;
    }

    async function load() {
        request?.abort();
        const controller = new AbortController();
        request = controller;
        loading = true;
        root.setAttribute('aria-busy', 'true');
        find('prev').disabled = true;
        find('next').disabled = true;
        const timeout = setTimeout(() => controller.abort('timeout'), 15000);
        try {
            const params = new URLSearchParams({...filter, page});
            const response = await fetch(`/api/admin/people-counter?${params}`, {cache: 'no-store', signal: controller.signal});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Không tải được dữ liệu bộ đếm.');
            if (request !== controller) return;
            render(data);
            find('error').classList.add('hidden');
        } catch (error) {
            if (request !== controller) return;
            find('error').textContent = controller.signal.aborted ? 'Kết nối quá lâu. Bấm Xem báo cáo để thử lại.' : error.message;
            find('error').classList.remove('hidden');
            find('notice').textContent = 'Chưa tải được báo cáo. Số lượt trực tiếp cạnh camera được cập nhật riêng từ dịch vụ AI.';
            for (const name of ['report-in', 'report-out', 'report-total']) find(name).textContent = '—';
            find('chart').replaceChildren();
            find('page').textContent = '';
            find('updated').textContent = '';
            messageRow('Chưa tải được lịch sử.');
        } finally {
            clearTimeout(timeout);
            if (request === controller) {
                loading = false;
                root.setAttribute('aria-busy', 'false');
            }
        }
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        filter = Object.fromEntries(new FormData(form));
        page = 1;
        load();
    });
    find('prev').addEventListener('click', () => {
        page = Math.max(1, page - 1);
        load();
    });
    find('next').addEventListener('click', () => {
        page = Math.min(pages, page + 1);
        load();
    });
    setInterval(() => {
        if (!document.hidden && !loading) load();
    }, 5000);
    load();
    loadPreview();
    startVideo();
})();
