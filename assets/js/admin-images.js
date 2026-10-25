/**
 * Product image editor.
 *
 * Every slot accepts either a Google Drive share link or a normal https image
 * URL (manufacturer pages, CDNs, unsplash…). The browser normalises the link the
 * same way PHP does, shows a live thumbnail and calls out anything that is not a
 * usable image URL before the form is saved.
 */
(function () {
  "use strict";

  var grid = document.querySelector("[data-gallery-grid]");
  if (!grid) return;

  var countBadge = document.querySelector("[data-gallery-count]");
  var pasteToggle = document.querySelector("[data-image-paste-toggle]");
  var pasteBoxWrap = document.querySelector("[data-image-paste]");
  var pasteBox = document.querySelector("[data-image-paste-box]");
  var pasteApply = document.querySelector("[data-image-paste-apply]");
  var pasteCancel = document.querySelector("[data-image-paste-cancel]");
  var pasteStatus = document.querySelector("[data-image-paste-status]");
  var clearAll = document.querySelector("[data-image-clear]");

  var slotInputs = function () {
    return Array.prototype.slice.call(grid.querySelectorAll("[data-image-input]"));
  };

  /* ------------------------------------------------------------- normalise */

  var DRIVE_PATTERNS = [
    /drive\.google\.com\/file\/d\/([A-Za-z0-9_-]{10,})/,
    /drive\.google\.com\/(?:open|thumbnail|uc)\?[^\s#]*?id=([A-Za-z0-9_-]{10,})/,
    /drive\.usercontent\.google\.com\/\w+\?[^\s#]*?id=([A-Za-z0-9_-]{10,})/,
    /docs\.google\.com\/[\w-]+\/d\/([A-Za-z0-9_-]{10,})/,
    /lh3\.googleusercontent\.com\/d\/([A-Za-z0-9_-]{10,})/
  ];

  function driveId(value) {
    for (var i = 0; i < DRIVE_PATTERNS.length; i++) {
      var match = value.match(DRIVE_PATTERNS[i]);
      if (match) return match[1];
    }
    if (!value.indexOf("drive:") && value.length > 25) return null;
    if (/^[A-Za-z0-9_-]{25,}$/.test(value) && value.indexOf("/") === -1 && value.indexOf(".") === -1) {
      return value;
    }
    return null;
  }

  /** @returns {{ok:boolean, url:string, kind:string, reason:string}} */
  function normalize(value) {
    var raw = (value || "").trim().replace(/^[\s"'<>]+|[\s"'<>]+$/g, "");
    if (!raw) return { ok: false, url: "", kind: "empty", reason: "" };
    if (/^\s*(javascript|data|vbscript|file):/i.test(raw)) {
      return { ok: false, url: "", kind: "bad", reason: "That link cannot be used for an image." };
    }
    var id = driveId(raw);
    if (id) {
      return {
        ok: true,
        url: "https://drive.google.com/file/d/" + id + "/view",
        kind: "drive",
        reason: "Google Drive file detected."
      };
    }
    if (raw.indexOf("//") === 0) raw = "https:" + raw;
    if (!/^https?:\/\//i.test(raw)) {
      if (/^[\w.-]+\.[a-z]{2,}(\/|\?)/i.test(raw)) {
        raw = "https://" + raw;
      } else {
        return { ok: false, url: "", kind: "bad", reason: "Paste a Drive share link or a full https link." };
      }
    }
    try {
      var parsed = new URL(raw);
      if (parsed.protocol !== "http:" && parsed.protocol !== "https:" || !parsed.hostname) {
        return { ok: false, url: "", kind: "bad", reason: "Only http(s) links are supported." };
      }
      return { ok: true, url: parsed.href, kind: "web", reason: "Web image link." };
    } catch (error) {
      return { ok: false, url: "", kind: "bad", reason: "That does not look like a valid link." };
    }
  }

  /* --------------------------------------------------------------- preview */

  function previewSrc(url) {
    return "/image.php?src=" + encodeURIComponent(url) + "&w=160";
  }

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
    img.width = 44;
    img.height = 44;
    img.src = previewSrc(result.url);
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

    // Store the normalised value so what you see is exactly what is saved.
    if (input.value.trim() !== result.url && !input.matches(":focus")) {
      input.value = result.url;
    }
  }

  function refreshAll() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-image-row]"), paint);
    if (countBadge) {
      var filled = slotInputs().filter(function (input) {
        return normalize(input.value).ok;
      }).length;
      countBadge.textContent = filled + " / " + slotInputs().length;
      countBadge.className = "badge " + (filled > 0 ? "text-bg-success" : "text-bg-light");
    }
  }

  document.addEventListener("input", function (event) {
    var row = event.target.closest("[data-image-row]");
    if (row) paint(row);
    if (event.target.matches("[data-image-input]")) {
      var filled = slotInputs().filter(function (input) {
        return normalize(input.value).ok;
      }).length;
      if (countBadge) {
        countBadge.textContent = filled + " / " + slotInputs().length;
        countBadge.className = "badge " + (filled > 0 ? "text-bg-success" : "text-bg-light");
      }
    }
  });

  document.addEventListener("paste", function (event) {
    var row = event.target.closest("[data-image-row]");
    if (!row) return;
    var text = (event.clipboardData || window.clipboardData).getData("text");
    if (!text || text.indexOf("\n") === -1) return;
    event.preventDefault();
    var input = row.querySelector("[data-image-input]");
    var first = text.split(/\r?\n/)[0].trim();
    if (input) input.value = first;
    paint(row);
    fillFromList(text.split(/\r?\n/), input);
  });

  /** Fill empty slots with the given links, starting after `afterInput`. */
  function fillFromList(lines, afterInput) {
    var inputs = slotInputs();
    var startIndex = afterInput ? inputs.indexOf(afterInput) + 1 : 0;
    var added = 0;
    var skipped = 0;
    var slot = startIndex;
    lines.forEach(function (line) {
      var result = normalize(line);
      if (!result.url) return;
      while (slot < inputs.length) {
        var target = inputs[slot++];
        if (!normalize(target.value).url) {
          target.value = result.url;
          var row = target.closest("[data-image-row]");
          if (row) paint(row);
          added++;
          break;
        }
      }
      if (slot >= inputs.length) skipped++;
    });
    refreshAll();
    return { added: added, skipped: skipped };
  }

  /* ----------------------------------------------------------- paste modal */

  if (pasteToggle && pasteBoxWrap && pasteBox) {
    pasteToggle.addEventListener("click", function () {
      pasteBoxWrap.classList.toggle("d-none");
      if (!pasteBoxWrap.classList.contains("d-none")) pasteBox.focus();
    });
    if (pasteCancel) {
      pasteCancel.addEventListener("click", function () {
        pasteBoxWrap.classList.add("d-none");
        pasteBox.value = "";
        if (pasteStatus) pasteStatus.textContent = "";
      });
    }
    if (pasteApply) {
      pasteApply.addEventListener("click", function () {
        var lines = pasteBox.value.split(/\r?\n/);
        var result = fillFromList(lines, null);
        if (pasteStatus) {
          pasteStatus.textContent = result.added + " link(s) added" +
            (result.skipped ? " · " + result.skipped + " did not fit in 12 slots" : "");
        }
      });
    }
  }

  if (clearAll) {
    clearAll.addEventListener("click", function () {
      if (!window.confirm("Clear every gallery slot? The primary image is kept.")) return;
      slotInputs().forEach(function (input) {
        input.value = "";
        var row = input.closest("[data-image-row]");
        if (row) paint(row);
      });
      refreshAll();
    });
  }

  /* Initial paint (edit mode) — normalise values already stored in the DB. */
  Array.prototype.forEach.call(document.querySelectorAll("[data-image-row]"), function (row) {
    var input = row.querySelector("[data-image-input]");
    if (input && input.value.trim()) {
      var result = normalize(input.value);
      if (result.ok) input.value = result.url;
    }
    paint(row);
  });
  refreshAll();
})();
