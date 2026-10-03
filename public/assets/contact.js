// Native <details> remains usable when JavaScript is unavailable.
document.querySelectorAll('[data-contact-widget]').forEach(widget => {
  const toggle = widget.querySelector('summary');
  const close = restoreFocus => {
    if (!widget.open) return;
    widget.open = false;
    if (restoreFocus) toggle.focus();
  };
  widget.querySelector('[data-contact-backdrop]').addEventListener('click', () => close(true));
  document.addEventListener('click', event => {
    if (!widget.contains(event.target)) close(widget.contains(document.activeElement));
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && widget.open) {
      event.preventDefault();
      close(true);
    }
  });
});
