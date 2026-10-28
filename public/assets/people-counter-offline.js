(() => {
    'use strict';

    function parseReport(text) {
        const invalid = () => new Error('File chưa đúng định dạng báo cáo video. Hãy chọn report.json do công cụ đếm xuất ra.');
        let report;
        try {
            report = JSON.parse(text);
        } catch {
            throw invalid();
        }
        const count = value => Number.isSafeInteger(value) && value >= 0 && value <= 10000000;
        const reference = value => value === null || count(value);
        if (!report || typeof report !== 'object' || Array.isArray(report)
            || !['complete', 'incomplete'].includes(report.status)
            || typeof report.sourceFile !== 'string' || !report.sourceFile || report.sourceFile.length > 1024
            || typeof report.sourceSha256 !== 'string' || !/^[a-f0-9]{64}$/i.test(report.sourceSha256)
            || !report.totals || !count(report.totals.IN) || !count(report.totals.OUT)
            || !report.expected || !reference(report.expected.IN) || !reference(report.expected.OUT)
            || !Number.isSafeInteger(report.processedFrames) || report.processedFrames <= 0
            || !Number.isFinite(report.videoSeconds) || report.videoSeconds <= 0
            || report.operationalEventsSent !== 0 || typeof report.calibrationVerified !== 'boolean'
            || !(report.cameraStart === null || (typeof report.cameraStart === 'string'
                && /^\d{4}-\d{2}-\d{2}T/.test(report.cameraStart)
                && Number.isFinite(Date.parse(report.cameraStart))))) {
            throw invalid();
        }
        return report;
    }

    if (typeof module !== 'undefined') module.exports = {parseReport};
    if (typeof document === 'undefined') return;
    const root = document.querySelector('[data-offline-counter]');
    if (!root) return;
    const find = name => root.querySelector(`[data-offline-${name}]`);
    const numbers = new Intl.NumberFormat('vi-VN');
    const signedNumbers = new Intl.NumberFormat('vi-VN', {signDisplay: 'always'});
    const dates = new Intl.DateTimeFormat('vi-VN', {
        timeZone: 'Asia/Ho_Chi_Minh', dateStyle: 'short', timeStyle: 'medium'
    });
    let selection = 0;

    function showReport(report) {
        find('in').textContent = numbers.format(report.totals.IN);
        find('out').textContent = numbers.format(report.totals.OUT);
        find('total').textContent = numbers.format(report.totals.IN + report.totals.OUT);
        find('video').textContent = report.sourceFile;
        const seconds = Math.round(report.videoSeconds);
        const pad = value => String(value).padStart(2, '0');
        find('duration').textContent = `${pad(Math.floor(seconds / 3600))}:${pad(Math.floor(seconds / 60) % 60)}:${pad(seconds % 60)}`;
        find('start').textContent = report.cameraStart ? dates.format(new Date(report.cameraStart)) : 'Chưa nhập · xem mốc phút/giây trong CSV';
        find('calibration').textContent = report.calibrationVerified ? 'Đã xác minh trong cấu hình báo cáo' : 'Chưa xác minh · cần kiểm tra bằng người đi thật';
        for (const [direction, suffix] of [['IN', 'in'], ['OUT', 'out']]) {
            find(`ai-${suffix}`).textContent = numbers.format(report.totals[direction]);
            const expected = report.expected[direction];
            find(`expected-${suffix}`).textContent = expected === null ? 'Chưa nhập' : numbers.format(expected);
            find(`diff-${suffix}`).textContent = expected === null ? '—' : signedNumbers.format(report.totals[direction] - expected);
        }
        const notes = [];
        if (report.status === 'incomplete') notes.push('Video chưa được xử lý đầy đủ. Số lượt chỉ thuộc phần đã đọc được.');
        if (!report.calibrationVerified) notes.push('Vạch đếm chưa được xác minh. Kiểm tra lại các lượt trong video trước khi đối soát.');
        if (!notes.length) notes.push('Đã đọc đủ video theo thông tin trong file. Cần đối chiếu các lượt AI ghi nhận với vận hành thực tế.');
        find('review').textContent = notes.join(' ');
        find('results').classList.remove('hidden');
        find('results').classList.add('grid');
    }

    find('file').addEventListener('change', async event => {
        const version = ++selection;
        const file = event.target.files[0];
        find('results').classList.add('hidden');
        find('results').classList.remove('grid');
        find('error').classList.add('hidden');
        if (!file) {
            find('status').textContent = 'Chưa mở báo cáo. Số liệu sẽ hiển thị sau khi bạn chọn file.';
            return;
        }
        find('status').textContent = 'Đang đọc báo cáo…';
        try {
            if (file.size > 65536) throw new Error('File quá lớn. Hãy chọn report.json, không chọn video hoặc events.csv.');
            const report = parseReport(await file.text());
            if (version !== selection) return;
            showReport(report);
            find('status').textContent = `Đã mở báo cáo: ${file.name}`;
        } catch (error) {
            if (version !== selection) return;
            find('status').textContent = 'Chưa mở được báo cáo.';
            find('error').textContent = error.message || 'Không đọc được file báo cáo.';
            find('error').classList.remove('hidden');
        }
    });
})();
