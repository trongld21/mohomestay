(() => {
    "use strict";

    const prefix = document.documentElement.dataset.branchPrefix || "";
    const branchUrl = (value) => {
        if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//")
            || /^\/(chi-nhanh\/|assets\/|images\/|uploads\/|__dev\/)/.test(value)) {
            return value;
        }
        return prefix + value;
    };
    window.moBranchUrl = branchUrl;
    window.moBranchStorageKey = (value) => prefix ? prefix + ":" + value : value;

    // All existing modules use fetch. Pin their API requests to the branch of
    // this document rather than a shared cookie that another tab could change.
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, options) => {
        if (typeof input === "string") {
            input = branchUrl(input);
        } else if (input instanceof URL && input.origin === location.origin) {
            input = new URL(branchUrl(input.pathname) + input.search, input.origin);
        } else if (input instanceof Request) {
            const url = new URL(input.url);
            if (url.origin === location.origin) {
                input = new Request(new URL(branchUrl(url.pathname) + url.search, url.origin), input);
            }
        }
        return originalFetch(input, options);
    };

    // Covers links created later by room/calendar/admin templates in JavaScript.
    document.addEventListener("click", (event) => {
        const link = event.target.closest?.("a[href]");
        if (link && !link.hasAttribute("data-branch-switch")) {
            link.setAttribute("href", branchUrl(link.getAttribute("href")));
        }
    }, true);
    document.addEventListener("submit", (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.hasAttribute("action")) {
            form.setAttribute("action", branchUrl(form.getAttribute("action")));
        }
    }, true);

    if (prefix) {
        const scopeLinks = (node) => {
            if (!(node instanceof Element)) {
                return;
            }
            const selector = "a[href]:not([data-branch-switch]), form[action]";
            const elements = [...node.querySelectorAll(selector)];
            if (node.matches(selector)) {
                elements.push(node);
            }
            for (const element of elements) {
                const attribute = element.tagName === "FORM" ? "action" : "href";
                const value = element.getAttribute(attribute);
                const scoped = branchUrl(value);
                if (scoped !== value) {
                    element.setAttribute(attribute, scoped);
                }
            }
        };
        // Keep copied links, context-menu opening and middle clicks scoped too.
        new MutationObserver((records) => {
            for (const record of records) {
                if (record.type === "attributes") {
                    scopeLinks(record.target);
                } else {
                    record.addedNodes.forEach(scopeLinks);
                }
            }
        }).observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["href", "action"],
        });
    }
})();
