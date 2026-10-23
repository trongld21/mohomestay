(() => {
    document.querySelectorAll('[data-identity-upload]').forEach(group => {
        const urls = new Map();
        const revoke = input => {
            if (urls.has(input)) URL.revokeObjectURL(urls.get(input));
            urls.delete(input);
        };
        group.addEventListener('change', event => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
            const preview = input.closest('label').querySelector('[data-identity-preview]');
            revoke(input);
            preview.removeAttribute('src');
            preview.hidden = true;
            input.setCustomValidity('');
            const file = input.files[0];
            let error = '';
            if (file && (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 3 * 1024 * 1024)) {
                error = 'Chọn ảnh JPG, PNG hoặc WebP tối đa 3 MiB. Ảnh HEIC cần đổi sang JPG trước khi tải.';
                input.setCustomValidity(error);
            } else if (file) {
                const url = URL.createObjectURL(file);
                urls.set(input, url);
                preview.src = url;
                preview.hidden = false;
            }
            group.querySelector('[data-identity-upload-message]').textContent = error;
        });
        group.closest('form')?.addEventListener('reset', () => {
            group.querySelectorAll('input[type="file"]').forEach(input => {
                revoke(input);
                input.setCustomValidity('');
            });
            group.querySelectorAll('[data-identity-preview]').forEach(preview => {
                preview.hidden = true;
                preview.removeAttribute('src');
            });
            group.querySelector('[data-identity-upload-message]').textContent = '';
        });
        window.addEventListener('pagehide', () => [...urls.keys()].forEach(revoke));
    });
})();
