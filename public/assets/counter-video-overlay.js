((scope) => {
    'use strict';

    function contentRect(width, height, videoWidth, videoHeight) {
        const scale = Math.min(width / videoWidth, height / videoHeight);
        const w = videoWidth * scale;
        const h = videoHeight * scale;
        return {x: (width - w) / 2, y: (height - h) / 2, width: w, height: h};
    }

    function nearestFrame(frames, at) {
        if (!Number.isFinite(at)) return null;
        let best = null;
        let gap = 750;
        for (const frame of frames) {
            const difference = Math.abs(frame.atMs - at);
            if (difference < gap) {
                best = frame;
                gap = difference;
            }
        }
        return best;
    }

    function draw(canvas, video, line, frame) {
        const context = canvas.getContext('2d');
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        const ratio = Math.min(scope.devicePixelRatio || 1, 2);
        if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);
        }
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);
        if (!video.videoWidth || !video.videoHeight || !line) return;
        const rect = contentRect(width, height, video.videoWidth, video.videoHeight);
        const point = ([x, y]) => [rect.x + x * rect.width, rect.y + y * rect.height];
        const [x1, y1] = point(line[0]);
        const [x2, y2] = point(line[1]);
        context.strokeStyle = '#facc15';
        context.fillStyle = '#facc15';
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(x1, y1);
        context.lineTo(x2, y2);
        context.stroke();
        context.font = '14px sans-serif';
        for (const box of frame?.boxes || []) {
            const [left, top] = point(box.rect.slice(0, 2));
            const [right, bottom] = point(box.rect.slice(2));
            context.strokeRect(left, top, right - left, bottom - top);
            const label = `#${box.id} ${box.direction === 'IN' ? 'VÀO' : box.direction === 'OUT' ? 'RA' : 'ĐANG THEO DÕI'}`;
            const labelTop = Math.max(rect.y, top - 22);
            context.fillStyle = '#1c1917';
            context.fillRect(left, labelTop, context.measureText(label).width + 10, 22);
            context.fillStyle = '#facc15';
            context.fillText(label, left + 5, labelTop + 16);
        }
    }

    const api = {contentRect, nearestFrame, draw};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else scope.CounterVideoOverlay = api;
})(globalThis);
