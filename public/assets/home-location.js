(() => {
    "use strict";

    const root = document.querySelector("[data-home-map]");
    if (!root) return;

    const canvas = root.querySelector("[data-map-canvas]");
    const status = root.querySelector("[data-map-status]");
    const message = root.querySelector("[data-map-message]");
    const retry = root.querySelector("[data-map-retry]");
    const center = [Number(root.dataset.mapLatitude), Number(root.dataset.mapLongitude)];
    const sdkSources = [
        "https://unpkg.com/leaflet@1.9.4/dist/",
        "https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/",
    ];
    let stylesheetPromise;
    let scriptPromise;
    let map;
    let resizeObserver;
    let timer;
    let loading = false;
    let disposed = false;

    function loadAsset(tag, url, integrity) {
        return new Promise((resolve, reject) => {
            const element = document.createElement(tag);
            const timeout = setTimeout(fail, 8000);
            function fail() {
                clearTimeout(timeout);
                element.remove();
                reject(new Error("Map asset unavailable"));
            }
            element.integrity = integrity;
            element.crossOrigin = "anonymous";
            element.onload = () => {
                clearTimeout(timeout);
                resolve();
            };
            element.onerror = fail;
            if (tag === "script") {
                element.src = url;
                element.async = true;
            } else {
                element.rel = "stylesheet";
                element.href = url;
            }
            document.head.append(element);
        });
    }

    function loadFromCdn(tag, filename, integrity) {
        return loadAsset(tag, sdkSources[0] + filename, integrity)
            .catch(() => loadAsset(tag, sdkSources[1] + filename, integrity));
    }

    function loadSdk() {
        stylesheetPromise ??= loadFromCdn("link", "leaflet.css", "sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=")
            .catch((error) => {
                stylesheetPromise = null;
                throw error;
            });
        if (!window.L) {
            scriptPromise ??= loadFromCdn("script", "leaflet.js", "sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=")
                .catch((error) => {
                    scriptPromise = null;
                    throw error;
                });
        }
        return Promise.all([stylesheetPromise, scriptPromise]);
    }

    function destroyMap() {
        clearTimeout(timer);
        resizeObserver?.disconnect();
        if (map) {
            const previous = map;
            map = null;
            previous.remove();
        }
    }

    function showError() {
        loading = false;
        destroyMap();
        status.hidden = false;
        retry.hidden = false;
        message.textContent = "Chưa tải được bản đồ. Bạn có thể thử lại hoặc mở chỉ đường.";
    }

    async function start() {
        if (loading || map || disposed) return;
        loading = true;
        retry.hidden = true;
        status.hidden = false;
        message.textContent = "Đang tải bản đồ…";
        try {
            await loadSdk();
            if (disposed) return;
            const sdk = window.L;
            if (!sdk) throw new Error("Map SDK unavailable");
            const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
            map = sdk.map(canvas, {
                attributionControl: true,
                zoomControl: false,
                scrollWheelZoom: false,
                zoomAnimation: !reducedMotion,
                fadeAnimation: !reducedMotion,
                markerZoomAnimation: !reducedMotion,
            }).setView(center, 17);
            const activeMap = map;
            sdk.control.zoom({
                position: "bottomright",
                zoomInTitle: "Phóng to",
                zoomOutTitle: "Thu nhỏ",
            }).addTo(map);
            const tiles = sdk.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
                maxZoom: 19,
                keepBuffer: 0,
                updateWhenIdle: true,
                referrerPolicy: "strict-origin-when-cross-origin",
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            });
            timer = setTimeout(showError, 15000);
            tiles.on("tileload", () => {
                if (map !== activeMap || !loading) return;
                clearTimeout(timer);
                loading = false;
                status.hidden = true;
            });
            tiles.on("load", () => {
                // If every tile failed, let Leaflet finish its event before removing the map.
                queueMicrotask(() => {
                    if (map === activeMap && loading) showError();
                });
            });
            tiles.addTo(map);
            const markerLabel = "Mơ Home";
            const icon = sdk.divIcon({
                className: "home-map-marker",
                iconSize: [36, 44],
                iconAnchor: [18, 44],
                tooltipAnchor: [0, -44],
                html: '<svg viewBox="0 0 36 44" aria-hidden="true"><path d="M18 1a16 16 0 0 0-16 16c0 11 16 26 16 26s16-15 16-26A16 16 0 0 0 18 1Z" fill="#dc2626" stroke="#fffaf2" stroke-width="2"/><circle cx="18" cy="17" r="5" fill="#fffaf2"/></svg>',
            });
            sdk.marker(center, { icon, title: markerLabel }).addTo(map).bindTooltip(markerLabel, {
                permanent: true,
                direction: "top",
                offset: [0, -6],
                className: "home-map-label",
            });
            resizeObserver = new ResizeObserver(() => map?.invalidateSize({ pan: false }));
            resizeObserver.observe(canvas);
        } catch {
            if (!disposed) showError();
        }
    }

    retry.addEventListener("click", start);
    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                observer.disconnect();
                start();
            }
        });
        observer.observe(root);
        window.addEventListener("pagehide", () => observer.disconnect(), { once: true });
    } else {
        start();
    }
    window.addEventListener("pagehide", () => {
        disposed = true;
        destroyMap();
    });
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) {
            disposed = false;
            loading = false;
            start();
        }
    });
})();
