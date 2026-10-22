(() => {
    "use strict";

    if (!document.body.classList.contains("admin-page")) return;
    function resetHorizontalOffset() {
        // Browsers can restore a horizontal viewport offset after focusing a popover.
        document.documentElement.scrollLeft = 0;
        document.body.scrollLeft = 0;
    }
    resetHorizontalOffset();
    window.addEventListener("pageshow", resetHorizontalOffset);
    document.addEventListener("wheel", (event) => {
        const input = event.target.closest?.('input[type="number"]');
        if (input && document.activeElement === input) {
            // Release native number stepping without blocking page scrolling.
            input.blur();
        }
    }, { capture: true, passive: true });
})();
