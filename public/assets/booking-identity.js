(() => {
    const form = document.querySelector('[data-booking-form]');
    if (form) {
        const dialog = form.querySelector('[data-booking-policy-dialog]');
        const open = form.querySelector('[data-booking-policy-open]');
        const checkbox = dialog.querySelector('[data-booking-policy-check]');
        const confirm = dialog.querySelector('[data-booking-policy-confirm]');
        const acceptance = form.elements.acceptBookingPolicies;
        const status = form.querySelector('[data-booking-policy-status]');

        function updateAcceptance() {
            status.textContent = acceptance.value === '1' ? 'Đã chấp nhận' : 'Chưa chấp nhận';
            acceptance.dispatchEvent(new Event('change'));
        }

        function showPolicies() {
            if (dialog.open) return;
            checkbox.checked = acceptance.value === '1';
            confirm.disabled = !checkbox.checked;
            dialog.showModal();
        }

        open.addEventListener('click', showPolicies);
        form.addEventListener('change', event => {
            const input = event.target;
            if (input instanceof HTMLInputElement && input.type === 'file'
                && input.closest('[data-identity-upload]') && input.files.length
                && input.validity.valid && acceptance.value !== '1') {
                showPolicies();
            }
        });
        checkbox.addEventListener('change', () => {
            confirm.disabled = !checkbox.checked;
            if (!checkbox.checked && acceptance.value === '1') {
                acceptance.value = '';
                updateAcceptance();
            }
        });
        confirm.addEventListener('click', () => {
            if (!checkbox.checked) return;
            acceptance.value = '1';
            updateAcceptance();
            dialog.close();
        });
        dialog.querySelector('[data-booking-policy-close]').addEventListener('click', () => dialog.close());
        form.addEventListener('reset', () => queueMicrotask(() => {
            acceptance.value = '';
            updateAcceptance();
            if (dialog.open) dialog.close();
        }));
    }

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
