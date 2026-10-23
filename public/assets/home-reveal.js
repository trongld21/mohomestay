(() => {
    "use strict";

    const sections = [...document.querySelectorAll("[data-home-reveal]")];
    if (!sections.length) return;
    const reduced = matchMedia("(prefers-reduced-motion: reduce)");
    if (reduced.matches || !("IntersectionObserver" in window)) return;
    const footer = document.querySelector(".site-footer");
    if (footer) sections.push(footer);

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            entry.target.classList.add("home-reveal-enter");
            observer.unobserve(entry.target);
        }
    }, { threshold: 0.06 });

    for (const section of sections) {
        observer.observe(section);
        section.addEventListener("animationend", (event) => {
            if (event.target === section && event.animationName === "home-section-enter") {
                section.classList.remove("home-reveal-enter");
            }
        });
        section.addEventListener("focusin", () => {
            section.classList.remove("home-reveal-enter");
            observer.unobserve(section);
        });
    }
    reduced.addEventListener("change", (event) => {
        if (!event.matches) return;
        observer.disconnect();
        sections.forEach((section) => section.classList.remove("home-reveal-enter"));
    });
})();
