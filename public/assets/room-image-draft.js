((root) => {
    function create(options = {}) {
        const createUrl = options.createUrl || ((file) => URL.createObjectURL(file));
        const revokeUrl = options.revokeUrl || ((url) => URL.revokeObjectURL(url));
        const makeKey = options.makeKey || (() => crypto.randomUUID());
        let items = [],
            removed = [];
        const coverFirst = () => items.forEach((image, index) => (image.isCover = index === 0));
        const renumber = () =>
            items.forEach((image, index) => (image.sortOrder = (index + 1) * 10));
        const dispose = (image) => {
            if (image.previewUrl) revokeUrl(image.previewUrl);
        };
        return {
            get items() {
                return items;
            },
            get pendingCount() {
                return items.filter((image) => image.file).length;
            },
            get removedCount() {
                return removed.length;
            },
            reset(images = []) {
                items.forEach(dispose);
                items = images.map((image) => ({
                    ...image,
                    key: image.id,
                    serverId: image.id,
                    savedCaption: image.caption,
                    savedOrder: image.sortOrder,
                    savedCover: image.isCover,
                }));
                removed = [];
                coverFirst();
            },
            add(files) {
                if (items.length + files.length > 12) throw new Error("Mỗi phòng tối đa 12 ảnh.");
                if (
                    files.some(
                        (file) =>
                            !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
                            file.size > 8 * 1024 * 1024,
                    )
                ) {
                    throw new Error("Chỉ nhận JPEG, PNG, WebP; mỗi ảnh không quá 8 MiB.");
                }
                const additions = [];
                try {
                    for (const file of files) {
                        const key = makeKey();
                        const previewUrl = createUrl(file);
                        additions.push({
                            key,
                            file,
                            previewUrl,
                            path: previewUrl,
                            caption: file.name.replace(/\.[^.]+$/, "").slice(0, 255),
                            sortOrder: 0,
                            isCover: false,
                        });
                    }
                } catch (error) {
                    additions.forEach(dispose);
                    throw error;
                }
                items.push(...additions);
                renumber();
                coverFirst();
            },
            remove(key) {
                const image = items.find((item) => item.key === key);
                if (!image) return;
                if (image.serverId) removed.push(image.serverId);
                dispose(image);
                items = items.filter((item) => item !== image);
                renumber();
                coverFirst();
            },
            move(key, target) {
                const index = items.findIndex((item) => item.key === key);
                if (index < 0 || target < 0 || target >= items.length || target === index) return;
                items.splice(target, 0, items.splice(index, 1)[0]);
                renumber();
                coverFirst();
            },
            update(key, values) {
                const image = items.find((item) => item.key === key);
                if (!image) return;
                if (values.caption !== undefined) image.caption = values.caption;
                if (values.sortOrder !== undefined) {
                    const order = Number(values.sortOrder);
                    if (!Number.isInteger(order) || order < 0 || order > 100000)
                        throw new Error("Thứ tự ảnh phải là số nguyên từ 0 đến 100.000.");
                    image.sortOrder = order;
                    items.sort((a, b) => a.sortOrder - b.sortOrder);
                    coverFirst();
                }
            },
            async save(roomId, send) {
                const base = "/api/admin/rooms/" + encodeURIComponent(roomId) + "/images";
                while (removed.length) {
                    await send("DELETE", base + "/" + encodeURIComponent(removed[0]), {});
                    removed.shift();
                }
                for (const image of items) {
                    if (image.file) {
                        const body = new FormData();
                        body.append("image", image.file);
                        body.append("caption", image.caption);
                        const result = await send("POST", base, body);
                        image.serverId = result.image.id;
                        dispose(image);
                        delete image.previewUrl;
                        image.file = null;
                        image.path = result.image.path;
                        image.savedCaption = result.image.caption;
                        image.savedOrder = result.image.sortOrder;
                        image.savedCover = result.image.isCover;
                    }
                    if (
                        image.savedCaption !== image.caption ||
                        image.savedOrder !== image.sortOrder
                    ) {
                        await send("PATCH", base + "/" + encodeURIComponent(image.serverId), {
                            caption: image.caption,
                            sortOrder: image.sortOrder,
                        });
                        image.savedCaption = image.caption;
                        image.savedOrder = image.sortOrder;
                    }
                }
                if (items.length && items.some((image) => image.savedCover !== image.isCover)) {
                    await send("PATCH", base + "/" + encodeURIComponent(items[0].serverId), {
                        isCover: true,
                    });
                    items.forEach((image) => (image.savedCover = image.isCover));
                }
            },
        };
    }
    if (typeof module !== "undefined" && module.exports) module.exports = { create };
    else root.MoRoomImageDraft = { create };
})(globalThis);
