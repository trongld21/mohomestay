(() => {
    const gallery = document.querySelector("[data-room-gallery]");
    if (!gallery) return;
    const main = gallery.querySelector("[data-gallery-main]");
    const caption = gallery.querySelector("[data-gallery-caption]");
    const current = gallery.querySelector("[data-gallery-current]");
    const thumbnails = [...gallery.querySelectorAll("[data-gallery-image]")];
    const dialog = gallery.querySelector("[data-room-lightbox]");
    const expand = gallery.querySelector("[data-gallery-expand]");
    const largeImage = gallery.querySelector("[data-lightbox-image]");
    const largeCaption = gallery.querySelector("[data-lightbox-caption]");
    const position = gallery.querySelector("[data-lightbox-position]");
    const images = thumbnails.length
        ? thumbnails.map((button) => ({
              src: button.dataset.galleryImage,
              caption: button.dataset.galleryCaption,
          }))
        : [{ src: main.getAttribute("src"), caption: main.alt }];
    let index = 0;
    function select(next) {
        index = ((next % images.length) + images.length) % images.length;
        const image = images[index];
        main.src = image.src;
        main.alt = image.caption;
        caption.textContent = image.caption;
        current.textContent = String(index + 1).padStart(2, "0");
        largeImage.src = image.src;
        largeImage.alt = image.caption;
        largeCaption.textContent = image.caption;
        position.textContent = `${index + 1} / ${images.length}`;
        thumbnails.forEach((button, number) => {
            button.classList.toggle("selected", number === index);
            button.setAttribute("aria-pressed", String(number === index));
        });
        if (!dialog.open) thumbnails[index]?.scrollIntoView({ block: "nearest", inline: "nearest" });
    }
    gallery.addEventListener("click", (event) => {
        const thumbnail = event.target.closest("[data-gallery-image]");
        if (thumbnail) select(thumbnails.indexOf(thumbnail));
        if (event.target.closest("[data-gallery-prev]")) select(index - 1);
        if (event.target.closest("[data-gallery-next]")) select(index + 1);
        if (event.target.closest("[data-gallery-expand]")) {
            dialog.showModal();
            document.body.classList.add("room-image-open");
        }
        if (event.target.closest("[data-lightbox-close]")) dialog.close();
    });
    gallery.addEventListener("keydown", (event) => {
        if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
            event.preventDefault();
            select(index + (event.key === "ArrowRight" ? 1 : -1));
        }
    });
    dialog.addEventListener("click", (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (
            event.clientX < bounds.left ||
            event.clientX > bounds.right ||
            event.clientY < bounds.top ||
            event.clientY > bounds.bottom
        )
            dialog.close();
    });
    dialog.addEventListener("close", () => {
        document.body.classList.remove("room-image-open");
        expand.focus();
    });
})();
