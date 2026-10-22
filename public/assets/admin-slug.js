((root) => {
    const normalize = (value) =>
        String(value)
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/đ/g, "d")
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/^-+|-+$/g, "");

    const conflict = (slug, rooms, id) =>
        rooms.some((room) => room.id !== id && room.slug === slug);
    const helpers = { normalize, conflict };
    if (typeof module !== "undefined" && module.exports) module.exports = helpers;
    else root.MoRoomSlug = helpers;
})(globalThis);
