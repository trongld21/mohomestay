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
        const inputs = [...group.querySelectorAll('input[type="file"]')];
        const message = group.querySelector('[data-identity-upload-message]');
        const publicBooking = form && group.closest('form') === form;
        let activeToast = null;
        let showMissing = false;

        function updateMessage() {
            const invalidFile = inputs.find(input => input.validity.customError);
            const missing = showMissing ? inputs.filter(input => input.validity.valueMissing) : [];
            const side = missing[0]?.name === 'identity_front' ? 'mặt trước' : 'mặt sau';
            const error = invalidFile?.validationMessage
                || (missing.length === 2 ? 'Vui lòng tải ảnh cả hai mặt CCCD.'
                    : missing.length ? 'Vui lòng tải ảnh ' + side + ' CCCD.' : '');
            if (!publicBooking || !window.LangUI?.toast) {
                message.textContent = error;
                return;
            }
            if (activeToast?.error === error && activeToast.element.isConnected
                && !activeToast.element.classList.contains('is-leaving')) return;
            activeToast?.close();
            activeToast = null;
            if (error) {
                const toast = window.LangUI.toast({ type: 'error', title: 'Kiểm tra ảnh CCCD', message: error });
                if (toast) {
                    toast.element.classList.add('booking-identity-toast');
                    activeToast = { ...toast, error };
                    return;
                }
            }
            message.textContent = error;
        }

        group.addEventListener('invalid', event => {
            if (!inputs.includes(event.target)) return;
            if (event.target.validity.valueMissing) showMissing = true;
            updateMessage();
            if (publicBooking && activeToast) {
                event.preventDefault();
                if (form.querySelector('input:invalid, select:invalid, textarea:invalid') === event.target) {
                    event.target.focus();
                }
            }
        }, true);

        const revoke = input => {
            if (urls.has(input)) URL.revokeObjectURL(urls.get(input));
            urls.delete(input);
        };
        group.addEventListener('change', event => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
            const side = input.closest('[data-identity-side]');
            const preview = side.querySelector('[data-identity-preview]');
            revoke(input);
            preview.removeAttribute('src');
            preview.hidden = true;
            input.setCustomValidity('');
            const file = input.files[0];
            if (file && (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 3 * 1024 * 1024)) {
                input.setCustomValidity('Chọn ảnh JPG, PNG hoặc WebP tối đa 3 MiB. Ảnh HEIC cần đổi sang JPG trước khi tải.');
            } else if (file) {
                const url = URL.createObjectURL(file);
                urls.set(input, url);
                preview.src = url;
                preview.hidden = false;
            }
            side.querySelector('[data-identity-remove]').hidden = preview.hidden;
            updateMessage();
        });
        group.addEventListener('click', event => {
            const remove = event.target.closest('[data-identity-remove]');
            if (!remove) return;
            const input = remove.closest('[data-identity-side]').querySelector('input[type="file"]');
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        });
        group.closest('form')?.addEventListener('reset', () => {
            inputs.forEach(input => {
                revoke(input);
                input.setCustomValidity('');
            });
            group.querySelectorAll('[data-identity-preview]').forEach(preview => {
                preview.hidden = true;
                preview.removeAttribute('src');
            });
            group.querySelectorAll('[data-identity-remove]').forEach(button => {
                button.hidden = true;
            });
            showMissing = false;
            updateMessage();
        });
        window.addEventListener('pagehide', () => [...urls.keys()].forEach(revoke));
    });
})();
