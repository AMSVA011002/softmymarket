/**
 * Shared image-link helpers for the dashboard.
 *
 * One place decides what a usable image link is, so the product gallery, the
 * hero slides and the store logo/favicon fields can never disagree:
 *
 *   - a Google Drive share link in any shape is folded into
 *     https://drive.google.com/file/d/FILE_ID/view (what /image.php expects)
 *   - a plain http(s) image URL is kept, adding https:// when the scheme was
 *     dropped from a copy/paste
 *   - javascript:, data:, vbscript: and file: are refused
 *
 * Also binds a lightweight preview to any [data-media-row] on the page:
 *   [data-media-row]
 *     [data-media-input]    the URL field
 *     [data-media-preview]  thumbnail container (gets an <img>)
 *     [data-media-status]   optional help/status line
 *
 * Exposes window.SMMMedia = { driveId, normalize, previewSrc, bind }.
 */
(function () {
  "use strict";

  var DRIVE_PATTERNS = [
    /drive\.google\.com\/file\/d\/([A-Za-z0-9_-]{10,})/,
    /drive\.google\.com\/(?:open|thumbnail|uc)\?[^\s#]*?id=([A-Za-z0-9_-]{10,})/,
    /drive\.usercontent\.google\.com\/\w+\?[^\s#]*?id=([A-Za-z0-9_-]{10,})/,
    /docs\.google\.com\/[\w-]+\/d\/([A-Za-z0-9_-]{10,})/,
    /lh3\.googleusercontent\.com\/d\/([A-Za-z0-9_-]{10,})/
  ];

  /** File id from any Drive link shape, or from a bare 25+ character id. */
  function driveId(value) {
    for (var i = 0; i < DRIVE_PATTERNS.length; i++) {
      var match = value.match(DRIVE_PATTERNS[i]);
      if (match) return match[1];
    }
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
      if ((parsed.protocol !== "http:" && parsed.protocol !== "https:") || !parsed.hostname) {
        return { ok: false, url: "", kind: "bad", reason: "Only http(s) links are supported." };
      }
      return { ok: true, url: parsed.href, kind: "web", reason: "Web image link." };
    } catch (error) {
      return { ok: false, url: "", kind: "bad", reason: "That does not look like a valid link." };
    }
  }

  /** Route a remote image through the store proxy (cached, hotlink-safe). */
  function previewSrc(url, width) {
    return "/image.php?src=" + encodeURIComponent(url) + "&w=" + (width || 160);
  }

  /** Paint one [data-media-row]. */
  function paint(row) {
    var input = row.querySelector("[data-media-input]");
    var preview = row.querySelector("[data-media-preview]");
    var status = row.querySelector("[data-media-status]");
    if (!input || !preview) return;

    var width = parseInt(row.getAttribute("data-media-width") || "160", 10) || 160;
    var result = normalize(input.value);
    if (!result.ok) {
      preview.innerHTML = '<i class="bi bi-image"></i>';
      preview.classList.remove("has-image", "is-bad");
      if (status) {
        status.textContent = result.reason || (row.getAttribute("data-media-empty") || "No image selected.");
        status.className = "form-text" + (result.kind === "bad" ? " text-danger" : "");
      }
      return;
    }

    preview.innerHTML = "";
    var img = document.createElement("img");
    img.alt = "";
    img.loading = "lazy";
    img.src = previewSrc(result.url, width);
    img.addEventListener("error", function () {
      preview.classList.add("is-bad");
      if (status) {
        status.textContent = "Saved, but the image could not be previewed — check it is shared publicly.";
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

    // Show back the normalised value so what is previewed is what is saved.
    if (input.value.trim() !== result.url && !input.matches(":focus")) {
      input.value = result.url;
    }
  }

  function bind(scope) {
    var root = scope || document;
    var rows = Array.prototype.slice.call(root.querySelectorAll("[data-media-row]"));
    if (rows.length === 0) return;
    rows.forEach(paint);
    root.addEventListener("input", function (event) {
      var row = event.target.closest("[data-media-row]");
      if (row) paint(row);
    });
  }

  window.SMMMedia = { driveId: driveId, normalize: normalize, previewSrc: previewSrc, bind: bind, paint: paint };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { bind(document); });
  } else {
    bind(document);
  }
})();
