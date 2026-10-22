const assert = require("node:assert/strict");
const { create } = require("../public/assets/room-image-draft.js");

async function main() {
    let key = 0;
    const revoked = [];
    const draft = create({
        makeKey: () => `pending-${++key}`,
        createUrl: (file) => `blob:${file.name}`,
        revokeUrl: (url) => revoked.push(url),
    });
    const existing = [
        { id: "a", path: "/a.jpg", caption: "A", sortOrder: 10, isCover: true },
        { id: "b", path: "/b.jpg", caption: "B", sortOrder: 20, isCover: false },
    ];
    draft.reset(existing);
    draft.move("b", 0);
    assert.equal(draft.items[0].serverId, "b");
    assert.equal(draft.items[0].isCover, true);
    assert.equal(draft.items[1].isCover, false);
    draft.update("b", { caption: "Ảnh bìa mới" });
    const calls = [];
    await draft.save("room", async (...args) => {
        calls.push(args);
    });
    assert.equal(calls.at(-1)[1], "/api/admin/rooms/room/images/b");
    assert.deepEqual(calls.at(-1)[2], { isCover: true });
    calls.length = 0;
    await draft.save("room", async (...args) => {
        calls.push(args);
    });
    assert.equal(calls.length, 0, "Unchanged metadata must not be written again.");
    draft.remove("b");
    assert.equal(draft.removedCount, 1);
    assert.equal(draft.items[0].isCover, true);
    await draft.save("room", async (...args) => {
        calls.push(args);
    });
    assert.equal(calls[0][0], "DELETE");
    assert.equal(draft.removedCount, 0);
    draft.reset(existing);
    draft.remove("a");
    draft.reset(existing);
    assert.equal(draft.removedCount, 0, "Discarding the editor must discard staged deletions.");
    assert.throws(() => draft.add([{ type: "text/html", size: 1 }]), /JPEG/);
    assert.throws(() => draft.add([{ type: "image/jpeg", size: 9 * 1024 * 1024 }]), /8 MiB/);
    assert.throws(() => draft.add(Array(11).fill({ type: "image/jpeg", size: 1 })), /12/);
    assert.equal(draft.items.length, 2, "Invalid batches must not partially enter the draft.");
    draft.reset();
    const first = new File(["fixture"], "first.jpg", { type: "image/jpeg" });
    const second = new File(["fixture"], "second.jpg", { type: "image/jpeg" });
    draft.add([first, second]);
    assert.equal(draft.pendingCount, 2);
    assert.equal(draft.items[0].path, "blob:first.jpg");
    let firstUploads = 0,
        secondUploads = 0;
    const send = async (method, url, body) => {
        if (method !== "POST") return {};
        const file = body.get("image");
        if (file.name === "first.jpg") firstUploads++;
        else if (++secondUploads === 1) throw new Error("Simulated upload failure");
        return {
            image: {
                id: file.name,
                path: `/uploads/${file.name}`,
                caption: body.get("caption"),
                sortOrder: file.name === "first.jpg" ? 10 : 20,
                isCover: file.name === "first.jpg",
            },
        };
    };
    await assert.rejects(draft.save("room", send), /Simulated/);
    assert.equal(draft.pendingCount, 1);
    await draft.save("room", send);
    assert.equal(firstUploads, 1, "Retry must not upload a successful file twice.");
    assert.equal(secondUploads, 2);
    assert.equal(draft.pendingCount, 0);
    assert.deepEqual(revoked, ["blob:first.jpg", "blob:second.jpg"]);
    draft.add([first]);
    draft.remove(draft.items.at(-1).key);
    assert.equal(
        revoked.at(-1),
        "blob:first.jpg",
        "Removing pending files must release preview URLs.",
    );
    console.log("Room image draft tests: OK");
}
main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
