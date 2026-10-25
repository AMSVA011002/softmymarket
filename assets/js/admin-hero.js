/**
 * Hero carousel editor (Admin → Settings → Hero slides).
 *
 * Talks to the same `/image.php` proxy as the product image editor, so a slide
 * image can be a Google Drive share link or any https image URL. Also handles
 * "Add slide" (clone the template card) and "Remove this slide" (dim the card;
 * the save handler skips every index that arrives in `slide_delete[]`).
 *
 * Everything degrades gracefully: without JS the same form still works — the
 * "Add slide" link reloads the tab with one more empty card and the removal
 * checkbox is a plain checkbox.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-hero-slides]");
  if (!form) return;

  var list = form.querySelector("[data-hero-slide-list]");
  var template = document.getElementById("hero-slide-template");
  var addButton = form.querySelector("[data-hero-add]");
  var counter = form.querySelector("[data-hero-count]");
  var limit = parseInt((addButton && addButton.getAttribute("data-hero-limit")) || "8", 10) || 8;
  var nextIndex = 0;

  /* ------------------------------------------------------------- normalise */

  // Shared with the product gallery and the logo/favicon fields.
  var media = window.SMMMedia;
  if (!media) {
    return; // media-url.js did not load — the editor still works as plain inputs
  }
  var normalize = media.normalize;

  /* --------------------------------------------------------------- preview */

  function paint(row) {
    var input = row.querySelector("[data-image-input]");
    var preview = row.querySelector("[data-image-preview]");
    var status = row.querySelector("[data-image-status]");
    if (!input || !preview) return;

    var result = normalize(input.value);
    if (!result.ok) {
      preview.innerHTML = '<i class="bi bi-image"></i>';
      preview.classList.remove("has-image", "is-bad");
      if (status) {
        status.textContent = result.reason;
        status.className = "form-text" + (result.kind === "bad" ? " text-danger" : "");
      }
      return;
    }

    preview.innerHTML = "";
    var img = document.createElement("img");
    img.alt = "";
    img.loading = "lazy";
    img.src = media.previewSrc(result.url, 320);
    img.addEventListener("error", function () {
      preview.classList.add("is-bad");
      if (status) {
        status.textContent = "Link saved, but the image could not be previewed — check it is shared publicly.";
        status.className = "form-text text-warning";
      }
    });
    preview.appendChild(img);
    preview.classList.add("has-image");
    preview.classList.remove("is-bad");
    if (status) {
      status.textContent = result.kind === "drive" ? "Google Drive — proxied and cached." : "Image link — proxied and cached.";
      status.className = "form-text text-success";
    }

    // Persist the normalised value so what is previewed is exactly what is saved.
    if (input.value.trim() !== result.url && !input.matches(":focus")) {
      input.value = result.url;
    }
  }

  function rows() {
    return Array.prototype.slice.call(form.querySelectorAll("[data-image-row]"));
  }

  /* ------------------------------------------------------------- add/remove */

  function cards() {
    return Array.prototype.slice.call(form.querySelectorAll("[data-hero-slide]"));
  }

  function refresh() {
    var all = cards();
    all.forEach(function (card, index) {
      var number = card.querySelector("[data-hero-number]");
      if (number) number.textContent = String(index + 1);
      var box = card.querySelector("[data-hero-delete]");
      // The posted value must match the position of the card's inputs in the
      // parallel slide_*[] arrays, so it is recomputed after every add/remove.
      if (box) box.value = String(index);
      var checked = box && box.checked;
      card.classList.toggle("is-removed", Boolean(checked));
    });
    if (counter) {
      counter.textContent = all.length + " / " + limit + " slides";
    }
    if (addButton) {
      var atLimit = all.length >= limit;
      addButton.classList.toggle("disabled", atLimit);
      addButton.setAttribute("aria-disabled", atLimit ? "true" : "false");
    }
  }

  if (addButton && template) {
    addButton.addEventListener("click", function (event) {
      if (cards().length >= limit) {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      nextIndex += 1;
      var markup = template.innerHTML.replace(/__INDEX__/g, "new" + nextIndex).replace(/__NUMBER__/g, "");
      var holder = document.createElement("div");
      holder.innerHTML = markup.trim();
      var card = holder.firstElementChild;
      if (!card) return;
      list.appendChild(card);
      refresh();
      var title = card.querySelector("[data-hero-title]");
      if (title) title.focus();
      card.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }

  form.addEventListener("change", function (event) {
    if (event.target.matches("[data-hero-delete]")) {
      var card = event.target.closest("[data-hero-slide]");
      if (card) card.classList.toggle("is-removed", event.target.checked);
      refresh();
    }
  });

  form.addEventListener("input", function (event) {
    var row = event.target.closest("[data-image-row]");
    if (row) paint(row);
  });

  // Multi-line paste (a column of links from a spreadsheet) keeps the first link.
  form.addEventListener("paste", function (event) {
    var row = event.target.closest("[data-image-row]");
    if (!row) return;
    var text = (event.clipboardData || window.clipboardData).getData("text");
    if (!text || text.indexOf("\n") === -1) return;
    event.preventDefault();
    var input = row.querySelector("[data-image-input]");
    if (input) {
      input.value = text.split(/\r?\n/)[0].trim();
      paint(row);
    }
  });

  rows().forEach(paint);
  refresh();
})();
