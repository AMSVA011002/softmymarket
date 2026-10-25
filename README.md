# SoftMyMarket

A production-ready, single-vendor marketplace for Sri Lanka — **PHP 8.1 + MySQL +
Bootstrap 5**, no Composer, no build step.

**Live deployment:** `softmymarket.hstn.me` (aeonfree free plan) — see
[docs/AEONFREE.md](docs/AEONFREE.md) for the step-by-step install.

The UI/UX and database design mirror the `softdealz` reference store
(same brand palette `#0c800f` / `#0a57a0`, LKR pricing, order-status pipeline,
Google-Drive image handling, admin dashboard structure) rebuilt on the
HTML/CSS/JS/PHP/MySQL stack.

---

## Highlights

**Storefront (mobile-first)**

* Parent + unlimited sub-categories (adjacency list with materialised `path`)
* 10,000+ products, faceted search (price, brand, rating, stock, condition, sale),
  sort, pagination, related products, flash deals
* Product gallery with thumbnails, specs table, reviews with rating breakdown, wishlist
* Cart, guest or account checkout, coupon codes, shipping rules, tax
* Six payment methods: **Cash on Delivery, Seylan Bank gateway (card), bank
  transfer, KOKO, Payzy, Mintpay**
* **Live order tracking** — public tracking by order number, customer timeline,
  admin live board streaming new orders
* Invoice (print/PDF-ready), order emails, saved addresses, account dashboard
* Google Drive / external image URLs proxied through `/image.php` with caching

**Admin dashboard**

* KPIs, sales chart, low-stock and pending-payment queues
* Orders: filter, status pipeline, tracking numbers, notes, refunds, timeline
* Live orders board with auto-refresh
* Products, categories (tree, computed depth, manual ordering), brands, inventory ledger
* Coupons, reviews moderation, customers, staff team, messages
* Payments ledger with bank-slip verification
* Reports (sales, categories, gateways, coupons, top products/customers) + CSV
* Settings (general, commerce, content, payments, hero slides), audit log
  — hero slides, the store logo and the favicon all take a Google Drive share link
  or any image URL, and hero slides can be added or removed
* Team accounts: add, edit, suspend, reactivate and delete — a suspended member
  keeps their history and can be switched back on, deleting is confirmed and audited
* Maintenance mode: one switch closes the storefront behind a branded holding page
  (HTTP 503 + `Retry-After`); the dashboard stays reachable, signed-in staff can
  preview the store, and JSON endpoints answer with a 503 payload instead of HTML
* Role-based access: **Admin**, **User** (store staff), **Customer**

---

## Requirements

| | |
| --- | --- |
| PHP | 8.1+ with `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo` |
| Database | MySQL 5.7+ / MariaDB 10.3+ (utf8mb4, InnoDB) |
| Web server | Apache 2.4 with `mod_rewrite` (Nginx config included in the docs) |
| Browser | Any modern mobile or desktop browser — Bootstrap is vendored locally |

---

## Quick start (shared hosting, no SSH)

1. Upload every folder to the web root — **including `config/config.php`**
   (git-ignored, it holds the DB password) or create it from
   `config/config.example.php`.
2. phpMyAdmin → select the database → **Import** →
   [`database/install.sql`](database/install.sql)
   (23 tables + settings + the first admin).
   Optional: [`database/demo-data.sql`](database/demo-data.sql) for 12 demo
   products, 12 categories, 8 brands and 5 coupons.
3. Open the site, sign in at `/login.php`, change the password, then
   **Admin → Tools → Lock the installer**.
4. Add the rest of the catalogue in **Admin → Tools → Seed demo data** (works
   without SSH, batched for free-plan time limits) or from the product screens.

### Command line (VPS / local)

```bash
php tools/deploy-check.php                                  # verify the deployment
php tools/seed.php --products=10000 --customers=10000 --orders=250
php tests/run.php            # unit tests, no DB writes
php tests/run.php --db       # + database integrity checks
```

Demo logins after seeding (change before going live):

| Role | Email | Password |
| --- | --- | --- |
| Admin (from `install.sql`) | `admin@softmymarket.hstn.me` | `SoftMy@Admin2026` |
| Customer (any seeded account) | see **Admin → Customers** | `Password123!` |

---

## Project layout

```
index.php  shop.php  category.php  product.php  cart.php  checkout.php
track.php  invoice.php  deals.php  brands.php  login.php  register.php …
admin/            dashboard (orders, products, payments, settings, reports, tools …)
account/          customer account pages
actions/          POST handlers (auth, cart, checkout, account, review …)
api/              JSON endpoints (cart, wishlist, live-orders, search-suggest)
payment/          start / return / notify / simulate / proof
includes/         core: Database, Auth, Security, Csrf, models, services
assets/           Bootstrap, Icons, app CSS/JS (vendored, no CDN)
database/         schema.sql (canonical), install.sql (deploy), demo-data.sql
tools/            seed.php, deploy-check.php (CLI diagnostics)
install/          three-step web installer
storage/          logs, cache, uploads, sessions, backups (not web-readable)
docs/             deployment, database, security documentation
tests/            CLI test suite
```

---

## Documentation

* [docs/AEONFREE.md](docs/AEONFREE.md) — the live deployment on aeonfree
  (`softmymarket.hstn.me`): upload, SQL import, first-run checklist, free-plan
  limits, backups
* [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — generic shared-hosting deployment, HTTPS,
  cron, gateways, mail, post-deploy checklist, Nginx notes
* [docs/DATABASE.md](docs/DATABASE.md) — credentials checklist, all 23 tables,
  status pipeline, category tree, seeding, maintenance SQL
* [docs/SECURITY.md](docs/SECURITY.md) — threat model and every control
  (prepared statements, CSRF, rate limits, sessions, permissions, uploads,
  payment callbacks), plus the pre-launch hardening checklist

---

## Configuration

Everything lives in `config/config.php` (git-ignored, HTTP-blocked, `0640`):

* `app` — name, URL, key, timezone, currency, support contacts
* `db` — host, port, name, user, password
* `session` — cookie name, lifetime, idle timeout, secure/samesite
* `security` — login attempt limits, lockout, max order quantity
* `mail` — sendmail or SMTP
* `payments` — per-gateway credentials, mode and limits
* `images` / `uploads` — proxy allow-list, sizes, cache directory

Gateway credentials can also be edited in **Admin → Settings → Payments** (stored
in the `settings` table and merged over the file at boot).

---

## Security

Prepared statements everywhere, bcrypt cost 12, a global CSRF guard on every POST,
database-backed rate limiting, strict nonce-based CSP, HttpOnly/SameSite session
cookies with rotation, role → permission checks on every dashboard page,
audit logging, validated uploads and HMAC-verified payment callbacks. Full detail
and the launch checklist: [docs/SECURITY.md](docs/SECURITY.md).

Do not commit `config/config.php`, and rotate the seeded demo passwords before
going live.

---

## Status of the live deployment

| Item | State |
| --- | --- |
| Database credentials | configured (`sql306.hstn.me` / `mseet_43261025_softmymarket`) |
| SQL scripts | ready — `database/install.sql` + `database/demo-data.sql` |
| Domain | `softmymarket.hstn.me` — set `app.url` once SSL is active |
| Payment gateways | COD + bank transfer enabled; Seylan / KOKO / Payzy / Mintpay await merchant credentials |
| SMTP | not configured — mail uses the host's sendmail |
| Demo data | import `demo-data.sql` or use Admin → Tools |

Rotate the database password that was shared during setup, and change the
seeded administrator password on first sign-in.
