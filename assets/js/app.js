/* ==========================================================================
   YourMarket — front-end behaviour (vanilla JS, no build step)
   All markup hooks use data-* attributes so inline handlers stay out of the
   templates (the Content-Security-Policy only allows external scripts).
   ========================================================================== */
(function () {
  "use strict";

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const csrfToken = () => {
    const input = $('input[name="_token"]');
    return input ? input.value : '';
  };

  const escapeHtml = (value) =>
    String(value == null ? "" : value).replace(/[&<>"']/g, (ch) => (
      { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch]
    ));

  const money = (value) => {
    const n = Number(value || 0);
    const symbol = document.documentElement.dataset.currencySymbol || "Rs";
    return symbol + " " + n.toLocaleString("en-LK", { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  /* ------------------------------------------------------------- toasts */
  function toast(message, type) {
    let stack = $(".toast-stack");
    if (!stack) {
      stack = document.createElement("div");
      stack.className = "toast-stack";
      document.body.appendChild(stack);
    }
    const tone = { success: "success", error: "danger", warning: "warning", info: "primary" }[type || "info"] || "primary";
    const el = document.createElement("div");
    el.className = "alert alert-" + tone + " shadow-sm d-flex align-items-start gap-2 mb-0 py-2 px-3";
    el.setAttribute("role", "status");
    el.style.minWidth = "220px";
    el.innerHTML = '<i class="bi bi-info-circle-fill mt-1"></i><div class="small">' +
      String(message).replace(/[<>&]/g, (c) => ({ "<": "&lt;", ">": "&gt;", "&": "&amp;" }[c])) +
      "</div>";
    stack.appendChild(el);
    setTimeout(() => {
      el.style.transition = "opacity .3s ease";
      el.style.opacity = "0";
      setTimeout(() => el.remove(), 320);
    }, 4200);
  }
  window.smmToast = toast;

  /* --------------------------------------------------------------- fetch */
  async function postJSON(url, payload) {
    const body = new FormData();
    Object.entries(payload || {}).forEach(([key, value]) => body.append(key, value));
    body.append("_token", csrfToken());
    const response = await fetch(url, {
      method: "POST",
      body,
      headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
      credentials: "same-origin",
    });
    let data;
    try {
      data = await response.json();
    } catch (error) {
      data = { error: "Unexpected response from the server." };
    }
    if (!response.ok) {
      throw Object.assign(new Error(data.error || "Request failed"), { data });
    }
    return data;
  }

  function setBusy(button, busy) {
    if (!button) return;
    button.disabled = busy;
    if (busy) {
      button.dataset.original = button.innerHTML;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
    } else if (button.dataset.original) {
      button.innerHTML = button.dataset.original;
      delete button.dataset.original;
    }
  }

  /* --------------------------------------------------------- header state */
  const header = $(".site-header");
  const backTop = $(".back-to-top");
  function onScroll() {
    if (header) header.classList.toggle("is-stuck", window.scrollY > 6);
    if (backTop) backTop.classList.toggle("show", window.scrollY > 480);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  if (backTop) backTop.addEventListener("click", () => window.scrollTo({ top: 0, behavior: "smooth" }));

  /* ------------------------------------------------------- mega menu */
  const megaBtn = $("[data-mega-toggle]");
  const megaPanel = $("[data-mega-panel]");
  if (megaBtn && megaPanel) {
    megaBtn.addEventListener("click", (event) => {
      event.preventDefault();
      const open = megaPanel.classList.toggle("open");
      megaBtn.setAttribute("aria-expanded", open ? "true" : "false");
    });
    document.addEventListener("click", (event) => {
      if (!megaPanel.classList.contains("open")) return;
      if (megaPanel.contains(event.target) || megaBtn.contains(event.target)) return;
      megaPanel.classList.remove("open");
      megaBtn.setAttribute("aria-expanded", "false");
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        megaPanel.classList.remove("open");
        megaBtn.setAttribute("aria-expanded", "false");
      }
    });
  }

  /* -------------------------------------------------- live search dropdown */

  const MIN_SEARCH = 3;

  /**
   * Live search for every search box on the page.
   *
   * Markup contract: a container holding an [data-search-input] and a
   * [data-suggest-box]. Any number of them can coexist (header, mobile drawer,
   * shop sidebar) — each keeps its own timer and request.
   */
  function initLiveSearch(root) {
    const input = $("[data-search-input]", root);
    const box = $("[data-suggest-box]", root);
    if (!input || !box) return;

    let timer = null;
    let controller = null;
    let items = [];
    let active = -1;
    let lastTerm = "";

    const setExpanded = (open) => {
      input.setAttribute("aria-expanded", open ? "true" : "false");
      box.classList.toggle("d-none", !open);
    };

    const hide = () => {
      clearTimeout(timer);
      if (controller) controller.abort();
      active = -1;
      items = [];
      box.innerHTML = "";
      setExpanded(false);
    };

    const hint = (message) => {
      box.innerHTML = '<div class="p-3 small text-ink-500 text-center">' + message + "</div>";
      setExpanded(true);
    };

    const loading = () => {
      box.innerHTML =
        '<div class="p-3 d-flex align-items-center gap-2 small text-ink-500">' +
        '<span class="spinner-border spinner-border-sm text-success" role="status" aria-hidden="true"></span>' +
        "Searching…</div>";
      setExpanded(true);
    };

    const row = (href, imageHtml, titleStrong, metaHtml) =>
      '<a class="d-flex align-items-center gap-2 text-decoration-none text-reset p-2 rounded-3 suggest-item" href="' +
      href + '" role="option">' + imageHtml +
      '<span class="flex-grow-1 min-w-0">' +
      '<span class="d-block small fw-semibold text-truncate">' + titleStrong + "</span>" +
      (metaHtml ? '<span class="d-block small text-ink-500 text-truncate">' + metaHtml + "</span>" : "") +
      "</span></a>";

    const render = (data, term) => {
      const parts = [];
      if (data.categories && data.categories.length) {
        parts.push('<div class="suggest-group">Categories</div>');
        data.categories.forEach((category) => {
          parts.push(
            row(category.url, '<span class="suggest-icon"><i class="bi bi-diagram-3"></i></span>',
              escapeHtml(category.name), "")
          );
        });
      }
      if (data.brands && data.brands.length) {
        parts.push('<div class="suggest-group">Brands</div>');
        data.brands.forEach((brand) => {
          parts.push(
            row(brand.url, '<span class="suggest-icon"><i class="bi bi-award"></i></span>',
              escapeHtml(brand.name), "")
          );
        });
      }
      if (data.products && data.products.length) {
        parts.push('<div class="suggest-group">Products</div>');
        data.products.forEach((product) => {
          const meta = [product.category, product.stock <= 0 ? "Out of stock" : ""]
            .filter(Boolean)
            .map(escapeHtml)
            .join(" · ");
          const price =
            '<span class="text-brand fw-bold">' + product.price + "</span>" +
            (product.was ? ' <span class="text-ink-400 text-decoration-line-through small">' + product.was + "</span>" : "") +
            (meta ? ' <span class="text-ink-400 small">· ' + meta + "</span>" : "");
          parts.push(
            row(product.url,
              '<img src="' + product.image + '" alt="" width="40" height="40" loading="lazy" ' +
              'style="object-fit:cover;border-radius:.5rem;background:var(--ink-100)">',
              escapeHtml(product.title), price)
          );
        });
      }

      if (!parts.length) {
        box.innerHTML =
          '<div class="p-3 text-center small">' +
          '<div class="text-ink-500 mb-1">No matches for “' + escapeHtml(term) + '”.</div>' +
          '<a class="fw-semibold" href="/shop.php?q=' + encodeURIComponent(term) + '">Browse the full catalogue</a></div>';
        setExpanded(true);
        items = [];
        return;
      }

      const total = Number(data.total || 0);
      parts.push(
        '<a class="d-block text-center small fw-semibold py-2 text-decoration-none suggest-more" href="' +
        escapeHtml(data.shop_url || "/shop.php?q=" + encodeURIComponent(term)) + '">' +
        (total > data.products.length
          ? "See all " + total.toLocaleString() + " results for “" + escapeHtml(term) + "”"
          : "Open results for “" + escapeHtml(term) + "”") +
        "</a>"
      );
      box.innerHTML = '<div class="p-1">' + parts.join("") + "</div>";
      items = Array.prototype.slice.call(box.querySelectorAll(".suggest-item"));
      active = -1;
      setExpanded(true);
    };

    const search = async (term) => {
      if (controller) controller.abort();
      controller = new AbortController();
      loading();
      try {
        const response = await fetch("/api/search-suggest.php?q=" + encodeURIComponent(term), {
          headers: { Accept: "application/json" },
          signal: controller.signal,
        });
        if (!response.ok) {
          hint("Search is unavailable right now. Press Enter to search the shop.");
          return;
        }
        const data = await response.json();
        if (input.value.trim() !== lastTerm) return; // a newer term won
        render(data, term);
      } catch (error) {
        if (error && error.name === "AbortError") return;
        hint("Search is unavailable right now. Press Enter to search the shop.");
      }
    };

    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    if (!box.id) box.id = "suggest-" + Math.random().toString(36).slice(2, 8);
    input.setAttribute("aria-controls", box.id);
    box.setAttribute("role", "listbox");

    input.addEventListener("input", () => {
      const term = input.value.trim();
      lastTerm = term;
      clearTimeout(timer);
      if (term.length === 0) {
        hide();
        return;
      }
      if (term.length < MIN_SEARCH) {
        hint("Keep typing — at least " + MIN_SEARCH + " letters for suggestions.");
        return;
      }
      timer = setTimeout(() => search(term), 200);
    });

    input.addEventListener("focus", () => {
      if (items.length) setExpanded(true);
    });

    input.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        hide();
        return;
      }
      if (!items.length) return;
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        active = event.key === "ArrowDown"
          ? (active + 1) % items.length
          : (active - 1 + items.length) % items.length;
        items.forEach((item, index) => item.classList.toggle("is-active", index === active));
        items[active].scrollIntoView({ block: "nearest" });
        return;
      }
      if (event.key === "Enter" && active >= 0) {
        event.preventDefault();
        window.location.href = items[active].getAttribute("href");
      }
    });

    input.addEventListener("blur", () => setTimeout(hide, 160));

    document.addEventListener("click", (event) => {
      if (!box.contains(event.target) && event.target !== input) hide();
    });
  }

  $$("[data-live-search]").forEach(initLiveSearch);

  /* ---------------------------------------- live filtering for admin tables */

  /**
   * Type-to-filter for dashboard and catalogue lists.
   *
   * The form keeps working without JS (submit button + GET), but with JS every
   * keystroke re-renders the results region from the server, so filtering stays
   * accurate across pagination — the browser never filters just the visible rows.
   *
   * Markup:   <form data-live-filter> … fields … </form>
   *           <div data-live-results> table + pagination </div>
   */
  function initLiveFilter(form) {
    const target = $(form.getAttribute("data-live-target") || "[data-live-results]");
    if (!target) return;

    const minChars = Number(form.getAttribute("data-live-min") || 0);
    const fields = $$("input, select, textarea", form);
    const searchFields = fields.filter((field) => field.matches("[data-live-input]"));
    let timer = null;
    let controller = null;

    const buildUrl = () => {
      const params = new URLSearchParams();
      new FormData(form).forEach((value, key) => {
        const text = String(value).trim();
        if (text === "") return;
        if (key === "page") return; // any filter change restarts at page 1
        params.append(key, text);
      });
      const query = params.toString();
      // Use the form's own target: /shop.php, /category.php?slug=… , /deals.php
      const action = (form.getAttribute("action") || location.pathname).split("?")[0];
      return action + (query ? "?" + query : "");
    };

    const setBusy = (busy) => {
      target.classList.toggle("is-loading", busy);
      target.setAttribute("aria-busy", busy ? "true" : "false");
    };

    const apply = async () => {
      const url = buildUrl();
      if (controller) controller.abort();
      controller = new AbortController();
      setBusy(true);
      try {
        const response = await fetch(url, {
          headers: { "X-Requested-With": "live-filter" },
          signal: controller.signal,
          credentials: "same-origin",
        });
        if (!response.ok) return;
        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, "text/html");
        const fresh = parsed.querySelector(form.getAttribute("data-live-target") || "[data-live-results]");
        if (!fresh) return;
        target.innerHTML = fresh.innerHTML;
        history.replaceState(null, "", url || location.pathname);
        if (window.bootstrap && window.bootstrap.Tooltip) {
          $$("[data-bs-toggle='tooltip']", target).forEach((el) => window.bootstrap.Tooltip.getOrCreateInstance(el));
        }
      } catch (error) {
        if (error && error.name === "AbortError") return;
      } finally {
        setBusy(false);
      }
    };

    const schedule = (delay) => {
      clearTimeout(timer);
      timer = setTimeout(apply, delay);
    };

    searchFields.forEach((field) => {
      field.addEventListener("input", () => {
        if (minChars && field.value.trim() !== "" && field.value.trim().length < minChars) {
          schedule(600);
          return;
        }
        schedule(220);
      });
      // Enter should not reload the page in a live-filtered form.
      field.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          clearTimeout(timer);
          apply();
        }
      });
    });

    fields.filter((field) => field.matches("select")).forEach((field) => {
      field.addEventListener("change", () => schedule(0));
    });

    form.addEventListener("submit", (event) => {
      event.preventDefault();
      clearTimeout(timer);
      apply();
    });
  }

  $$("form[data-live-filter]").forEach(initLiveFilter);

  /* -------- client-side instant filter for short, fully rendered tables ----- */

  $$("[data-live-client]").forEach((input) => {
    const scope = $(input.getAttribute("data-live-client")) || document;
    const rows = $$("[data-filter-row]", scope);
    const empty = $(input.getAttribute("data-live-empty") || "[data-filter-empty]", scope);
    const counter = $(input.getAttribute("data-live-count") || "[data-filter-count]", scope);
    const text = (row) => (row.getAttribute("data-filter-text") || row.textContent || "").toLowerCase();

    // Group nested rows (categories): a parent row owns the child rows below it,
    // so a child match keeps its parent visible instead of orphaning the tree.
    const groups = [];
    let current = null;
    rows.forEach((row) => {
      if (row.hasAttribute("data-filter-parent") || !row.hasAttribute("data-filter-child")) {
        current = { parent: row, children: [] };
        groups.push(current);
      } else if (current) {
        current.children.push(row);
      }
    });

    const apply = () => {
      const term = input.value.trim().toLowerCase();
      let shown = 0;
      groups.forEach(({ parent, children }) => {
        const parentMatch = term === "" || text(parent).indexOf(term) !== -1;
        const childMatch = children.some((child) => term !== "" && text(child).indexOf(term) !== -1);
        const groupVisible = parentMatch || childMatch;
        parent.classList.toggle("d-none", !groupVisible);
        if (groupVisible) shown++;
        children.forEach((child) => {
          const visible = groupVisible && (parentMatch || text(child).indexOf(term) !== -1);
          child.classList.toggle("d-none", !visible);
          if (visible) shown++;
        });
      });
      if (empty) empty.classList.toggle("d-none", shown !== 0);
      if (counter) counter.textContent = shown + " of " + rows.length;
    };

    input.addEventListener("input", apply);
    input.addEventListener("keydown", (event) => {
      if (event.key === "Enter") event.preventDefault();
      if (event.key === "Escape") { input.value = ""; apply(); }
    });
    apply();
  });

  /* --------------------------------------------------------- add to cart */
  let cartDrawerEl = null;
  function openCart() {
    if (!cartDrawerEl) cartDrawerEl = $("#cartDrawer");
    if (cartDrawerEl && window.bootstrap) {
      window.bootstrap.Offcanvas.getOrCreateInstance(cartDrawerEl).show();
    } else {
      window.location.href = "/cart.php";
    }
  }

  async function refreshCartDrawer() {
    const target = $("[data-cart-drawer-body]");
    if (!target) return;
    try {
      const response = await fetch("/api/cart.php?view=drawer", { headers: { Accept: "application/json" } });
      const data = await response.json();
      if (data.html) target.innerHTML = data.html;
      updateCartCount(data.count);
    } catch (error) { /* keep previous content */ }
  }

  function updateCartCount(count) {
    $$("[data-cart-count]").forEach((el) => {
      el.textContent = count;
      el.classList.toggle("is-empty", !count);
    });
  }

  document.addEventListener("click", async (event) => {
    const addBtn = event.target.closest("[data-add-to-cart]");
    if (addBtn) {
      event.preventDefault();
      const productId = addBtn.getAttribute("data-add-to-cart");
      const qtyInput = addBtn.closest("[data-product-actions]")?.querySelector("[data-qty-input]");
      const quantity = qtyInput ? qtyInput.value : 1;
      setBusy(addBtn, true);
      try {
        const data = await postJSON("/api/cart.php", { action: "add", product_id: productId, quantity });
        updateCartCount(data.count);
        toast(data.message || "Added to cart", "success");
        await refreshCartDrawer();
        if (addBtn.hasAttribute("data-open-drawer")) openCart();
      } catch (error) {
        toast(error.message, "error");
        if (error.data && error.data.login) setTimeout(() => { window.location.href = "/login.php"; }, 900);
      } finally {
        setBusy(addBtn, false);
      }
      return;
    }

    const wishBtn = event.target.closest("[data-wishlist]");
    if (wishBtn) {
      event.preventDefault();
      const productId = wishBtn.getAttribute("data-wishlist");
      setBusy(wishBtn, true);
      try {
        const data = await postJSON("/api/wishlist.php", { product_id: productId });
        wishBtn.classList.toggle("saved", !!data.saved);
        const icon = wishBtn.querySelector("i");
        if (icon) icon.className = data.saved ? "bi bi-heart-fill" : "bi bi-heart";
        toast(data.message, data.saved ? "success" : "info");
        $$("[data-wishlist-count]").forEach((el) => {
          el.textContent = data.total;
          el.classList.toggle("is-empty", !data.total);
        });
      } catch (error) {
        toast(error.message, "error");
        if (error.data && error.data.login) setTimeout(() => { window.location.href = "/login.php"; }, 900);
      } finally {
        setBusy(wishBtn, false);
      }
      return;
    }

    const removeBtn = event.target.closest("[data-cart-remove]");
    if (removeBtn) {
      event.preventDefault();
      try {
        const data = await postJSON("/api/cart.php", { action: "remove", product_id: removeBtn.getAttribute("data-cart-remove") });
        updateCartCount(data.count);
        await refreshCartDrawer();
        if (window.location.pathname.endsWith("/cart.php")) window.location.reload();
        toast(data.message || "Removed", "info");
      } catch (error) { toast(error.message, "error"); }
      return;
    }

    const openBtn = event.target.closest("[data-open-cart]");
    if (openBtn) {
      event.preventDefault();
      refreshCartDrawer();
      openCart();
      return;
    }

    const copyBtn = event.target.closest("[data-copy]");
    if (copyBtn) {
      event.preventDefault();
      const text = copyBtn.getAttribute("data-copy");
      try {
        await navigator.clipboard.writeText(text);
        toast("Copied to clipboard", "success");
      } catch (error) { toast("Copy failed — select the text manually.", "error"); }
      return;
    }

    const notifBtn = event.target.closest("[data-mark-all-read]");
    if (notifBtn) {
      event.preventDefault();
      try {
        await postJSON("/api/notifications.php", { action: "read_all" });
        $$("[data-notif-unread]").forEach((el) => el.classList.add("is-empty"));
        $$("[data-notification-item].unread").forEach((el) => el.classList.remove("unread"));
        toast("All notifications marked as read", "success");
      } catch (error) { toast(error.message, "error"); }
    }
  });

  /* ------------------------------------------------------- qty steppers */
  document.addEventListener("click", (event) => {
    const step = event.target.closest("[data-qty-step]");
    if (!step) return;
    const input = step.closest(".qty-stepper, [data-product-actions]")?.querySelector("[data-qty-input]");
    if (!input) return;
    const delta = Number(step.getAttribute("data-qty-step"));
    const min = Number(input.getAttribute("min") || 1);
    const max = Number(input.getAttribute("max") || 99);
    input.value = Math.max(min, Math.min(max, Number(input.value || 1) + delta));
  });

  /* ------------------------------------------------------ cart page qty */
  document.addEventListener("change", async (event) => {
    const input = event.target.closest("[data-cart-qty]");
    if (!input) return;
    try {
      const data = await postJSON("/api/cart.php", {
        action: "update",
        product_id: input.getAttribute("data-cart-qty"),
        quantity: input.value,
      });
      updateCartCount(data.count);
      window.location.reload();
    } catch (error) {
      toast(error.message, "error");
      window.location.reload();
    }
  });

  /* ----------------------------------------------------------- countdown */
  $$("[data-countdown]").forEach((el) => {
    const target = new Date(el.getAttribute("data-countdown")).getTime();
    const days = $("[data-cd-days]", el);
    const hours = $("[data-cd-hours]", el);
    const mins = $("[data-cd-mins]", el);
    const secs = $("[data-cd-secs]", el);
    const tick = () => {
      const diff = Math.max(0, target - Date.now());
      const d = Math.floor(diff / 86400000);
      const h = Math.floor((diff % 86400000) / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      const pad = (n) => String(n).padStart(2, "0");
      if (days) days.textContent = pad(d);
      if (hours) hours.textContent = pad(h);
      if (mins) mins.textContent = pad(m);
      if (secs) secs.textContent = pad(s);
      if (diff <= 0) clearInterval(interval);
    };
    const interval = setInterval(tick, 1000);
    tick();
  });

  /* ------------------------------------------------------------- gallery */
  $$("[data-gallery]").forEach((gallery) => {
    const main = $("[data-gallery-main]", gallery);
    $$("[data-gallery-thumb]", gallery).forEach((thumb) => {
      thumb.addEventListener("click", () => {
        if (main) main.src = thumb.getAttribute("data-gallery-thumb");
        $$("[data-gallery-thumb]", gallery).forEach((t) => t.classList.remove("active"));
        thumb.classList.add("active");
      });
    });
  });

  /* ------------------------------------------------------ payment buttons */
  $$("[data-payment-option]").forEach((option) => {
    option.addEventListener("click", () => {
      $$("[data-payment-option]").forEach((other) => other.classList.remove("selected"));
      option.classList.add("selected");
      const radio = option.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
    });
  });

  /* -------------------------------------------------- live order tracking */
  const tracker = $("[data-track-order]");
  if (tracker) {
    const orderId = tracker.getAttribute("data-track-order");
    const token = tracker.getAttribute("data-track-token") || "";
    const interval = Number(tracker.getAttribute("data-track-interval") || 20) * 1000;
    const endpoint = "/api/track.php?order=" + encodeURIComponent(orderId) + "&token=" + encodeURIComponent(token);
    let failures = 0;

    const render = (data) => {
      const badge = $("[data-track-status]", tracker);
      if (badge) badge.textContent = data.status_label;
      const progress = $("[data-track-progress]", tracker);
      if (progress) progress.style.width = data.progress + "%";
      const updated = $("[data-track-updated]", tracker);
      if (updated) updated.textContent = data.updated_human;
      const stepEl = $("[data-track-current]", tracker);
      if (stepEl) stepEl.textContent = data.status_label;
      const trackingRow = $("[data-track-courier]", tracker);
      if (trackingRow) {
        if (data.tracking_number) {
          trackingRow.classList.remove("d-none");
          const num = $("[data-track-number]", trackingRow);
          if (num) num.textContent = data.tracking_number;
          const carrier = $("[data-track-carrier]", trackingRow);
          if (carrier) carrier.textContent = data.carrier || "Courier";
          const link = $("[data-track-link]", trackingRow);
          if (link) {
            if (data.courier_url) { link.href = data.courier_url; link.classList.remove("d-none"); }
            else { link.classList.add("d-none"); }
          }
        } else {
          trackingRow.classList.add("d-none");
        }
      }
      const timeline = $("[data-track-timeline]", tracker);
      if (timeline && data.events) {
        const existing = timeline.getAttribute("data-count") || "0";
        if (String(data.events.length) !== existing) {
          timeline.innerHTML = data.events.map((event, index) => (
            '<div class="track-step ' + (index === data.events.length - 1 ? "current" : "done") + '">' +
            "<h4>" + event.label + "</h4>" +
            "<p>" + event.message + "</p>" +
            "<time>" + event.at + "</time>" +
            "</div>"
          )).join("");
          timeline.setAttribute("data-count", data.events.length);
        }
      }
      $$("[data-track-pill]").forEach((pill) => {
        pill.classList.toggle("live", data.status !== "DELIVERED" && data.status !== "CANCELLED");
      });
    };

    const poll = async () => {
      if (document.hidden) return;
      try {
        const response = await fetch(endpoint, { headers: { Accept: "application/json" } });
        if (!response.ok) throw new Error("poll failed");
        const data = await response.json();
        if (data.error) return;
        render(data);
        failures = 0;
      } catch (error) {
        failures += 1;
        if (failures > 4) clearInterval(timer);
      }
    };

    const timer = setInterval(poll, interval);
    setTimeout(poll, 1500);
  }

  /* -------------------------------------------------- admin live board */
  const liveBoard = $("[data-live-board]");
  if (liveBoard) {
    const interval = Number(liveBoard.getAttribute("data-live-board") || 20) * 1000;
    const countEl = $("[data-live-count]");
    const poll = async () => {
      if (document.hidden) return;
      try {
        const response = await fetch("/api/live-orders.php", { headers: { Accept: "application/json" } });
        if (!response.ok) return;
        const data = await response.json();
        if (countEl && typeof data.count === "number") countEl.textContent = data.count;
        const list = $("[data-live-list]");
        if (list && data.html) list.innerHTML = data.html;
      } catch (error) { /* retry next tick */ }
    };
    setInterval(poll, interval);
  }

  /* --------------------------------------------------------- form guards */
  $$("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      const message = form.getAttribute("data-confirm") || "Are you sure?";
      if (!window.confirm(message)) event.preventDefault();
    });
  });

  $$("form[data-disable-on-submit]").forEach((form) => {
    form.addEventListener("submit", () => {
      const btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; setBusy(btn, true); }
    });
  });

  /* ------------------------------------------------------------ printing */
  // Replaces inline onclick="window.print()" so the CSP can forbid inline JS.
  $$("[data-print]").forEach((button) => {
    button.addEventListener("click", () => window.print());
  });

  /* ------------------------------------------------- password visibility */
  $$("[data-toggle-password]").forEach((button) => {
    button.addEventListener("click", () => {
      const target = document.getElementById(button.getAttribute("data-toggle-password"));
      if (!target) return;
      target.type = target.type === "password" ? "text" : "password";
      const icon = button.querySelector("i");
      if (icon) icon.className = target.type === "password" ? "bi bi-eye" : "bi bi-eye-slash";
    });
  });

  /* ------------------------------------------------------ image fallback */
  document.addEventListener("error", (event) => {
    const img = event.target;
    if (img.tagName === "IMG" && !img.dataset.fallbackApplied) {
      img.dataset.fallbackApplied = "1";
      img.src = "/assets/img/placeholder.svg";
    }
  }, true);

  /* ---------------------------------------------------- admin sidebar */
  const sidebar = $(".admin-sidebar");
  const sidebarToggle = $("[data-sidebar-toggle]");
  if (sidebar && sidebarToggle) {
    sidebarToggle.addEventListener("click", () => sidebar.classList.toggle("open"));
    document.addEventListener("click", (event) => {
      if (window.innerWidth >= 992) return;
      if (sidebar.contains(event.target) || sidebarToggle.contains(event.target)) return;
      sidebar.classList.remove("open");
    });
  }

  /* ----------------------------------------------------- auto-subscribe */
  $$("[data-autosubmit]").forEach((el) => {
    el.addEventListener("change", () => el.form && el.form.submit());
  });

  /* ------------------------------------------------------- support dock */
  // One launcher keeps the dock from covering page content; the channels slide
  // out on tap and the links rejoin the tab order while open.
  const dock = $("[data-dock]");
  if (dock) {
    const toggle = $("[data-dock-toggle]", dock);
    const channels = $$(".support-channels a", dock);
    const setOpen = (open) => {
      dock.classList.toggle("is-open", open);
      if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
      channels.forEach((a) => {
        if (open) a.removeAttribute("tabindex");
        else a.setAttribute("tabindex", "-1");
      });
    };
    if (toggle) {
      toggle.addEventListener("click", (event) => {
        event.stopPropagation();
        setOpen(!dock.classList.contains("is-open"));
      });
    }
    document.addEventListener("click", (event) => {
      if (dock.classList.contains("is-open") && !dock.contains(event.target)) setOpen(false);
    });
    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape" || !dock.classList.contains("is-open")) return;
      setOpen(false);
      if (toggle) toggle.focus();
    });
    setOpen(false);
  }

  /* ------------------------------------------------------ brand marquee */
  // Only animate while the rail is on screen, so a long home page does not keep
  // compositing an off-screen track.
  const marquee = $("[data-brand-marquee]");
  if (marquee) {
    const track = $("[data-brand-track]", marquee);
    if (track && "IntersectionObserver" in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => track.classList.toggle("is-paused", !entry.isIntersecting));
      }, { rootMargin: "120px" });
      observer.observe(marquee);
    }
  }

  /* ------------------------------------------------------- hero carousel */
  // Respect the visitor's motion preference: no autoplay, no sliding.
  const heroCarousel = $("#heroCarousel");
  if (heroCarousel && window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    heroCarousel.removeAttribute("data-bs-ride");
    heroCarousel.removeAttribute("data-bs-interval");
  }

  /* --------------------------------------------- bootstrap tooltips */
  if (window.bootstrap) {
    $$('[data-bs-toggle="tooltip"]').forEach((el) => new window.bootstrap.Tooltip(el));
  }
})();
