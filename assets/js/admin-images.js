/*
 * Product image links editor.
 * One textarea accepts one public Google Drive or HTTPS image URL per line.
 * The first valid link is the primary image; the rest form the gallery.
 */
(() => {
  "use strict";

  const input = document.querySelector("[data-image-links]");
  const previewGrid = document.querySelector("[data-image-preview-grid]");
  if (!input || !previewGrid) return;

  const countBadge = document.querySelector("[data-gallery-count]");
  const status = document.querySelector("[data-image-links-status]");
  const media = window.SMMMedia;
  if (!media) return;

  const MAX_IMAGES = 20;

  function readLinks() {
    const seen = new Set();
    const valid = [];
    let invalid = 0;
    input.value.split(/\r?\n/).forEach((line) => {
      const value = line.trim();
      if (!value) return;
      const result = media.normalize(value);
      if (!result.ok) {
        invalid++;
        return;
      }
      if (seen.has(result.url)) return;
      seen.add(result.url);
      valid.push(result.url);
    });
    return { images: valid.slice(0, MAX_IMAGES), total: valid.length, invalid };
  }

  function render() {
    const result = readLinks();
    previewGrid.replaceChildren();
    result.images.forEach((url, index) => {
      const item = document.createElement("figure");
      item.className = "image-link-preview";
      const image = document.createElement("img");
      image.src = media.previewSrc(url, 160);
      image.alt = "";
      image.loading = index < 4 ? "eager" : "lazy";
      image.decoding = "async";
      image.addEventListener("error", () => {
        item.classList.add("is-bad");
        image.alt = "Preview unavailable";
      });
      const caption = document.createElement("figcaption");
      caption.textContent = index === 0 ? "Main image" : `Image ${index + 1}`;
      item.append(image, caption);
      previewGrid.append(item);
    });

    if (countBadge) {
      const shown = result.images.length;
      countBadge.textContent = `${shown} / ${MAX_IMAGES}`;
      countBadge.className = `badge ${shown ? "text-bg-success" : "text-bg-light"}`;
    }

    if (status) {
      const notes = [];
      if (result.total > MAX_IMAGES) notes.push(`Only the first ${MAX_IMAGES} unique valid links will be saved.`);
      if (result.invalid) notes.push(`${result.invalid} invalid link(s) will be ignored.`);
      if (result.total > result.images.length && result.total <= MAX_IMAGES) notes.push("Duplicate links are ignored.");
      status.textContent = notes.join(" ");
      status.className = `form-text mt-2${result.invalid ? " text-warning" : " text-ink-500"}`;
    }
  }

  input.addEventListener("input", render);
  render();
})();
