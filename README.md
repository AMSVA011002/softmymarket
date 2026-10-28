# YourMarket

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
* Seven payment methods: **Cash on Delivery, Seylan Bank gateway (card),
  PayHere, bank transfer, KOKO, Payzy, Mintpay**
* **Live order tracking** — public tracking by order number, customer timeline,
  admin live board streaming new orders
* Invoice (print/PDF-ready), order emails, saved addresses, account dashboard
* Customer registration requires a 24-hour email confirmation link before sign-in; the link’s Continue button verifies the account, signs the customer in and returns to the storefront
* Customer return requests within 7 days of delivery, with item quantities, reason, optional evidence, staff review and status timeline
* Google Drive / external image URLs proxied through `/image.php` with caching

**Admin dashboard**

* KPIs, sales chart, low-stock and pending-payment queues
* Orders: filter, status pipeline, tracking numbers, notes, payment records, timeline
* Returns: approve/reject, receive and inspect items, record manual refund/exchange outcomes, optionally restock
* Live orders board with auto-refresh
* Products, categories (tree, computed depth, manual ordering), brands, inventory ledger
* Coupons, reviews moderation, customers, staff team, messages
* Payments ledger with bank-slip verification
* Reports (sales, categories, gateways, coupons, top products/customers) + CSV
* Settings (general, commerce, content, authenticated SMTP/email delivery, payments, hero slides), audit log
  — hero slides, the store logo and the favicon all take a Google Drive share link
  or any image URL, and hero slides can be added or removed
* Team accounts: invite by email with the assigned role and temporary password; require a password change before first use, then edit, suspend, reactivate or delete accounts with audited history
* Maintenance mode: one switch closes the storefront behind a branded holding page
  (HTTP 503 + `Retry-After`); a store-team sign-in link leads directly to the
  commerce settings, login submissions remain available while registration stays
  blocked, the dashboard stays reachable, and JSON endpoints return a 503 payload
* Role-based access: **Admin**, **User** (store staff), **Customer**

---

## SEO, sharing and accessibility

Every storefront page ships the full set a health-check or search crawler looks
for, generated from one place (`includes/layout/header.php`):

* unique `<title>` + description, canonical URL, `robots` directives
* Open Graph (`og:type/site_name/title/description/url/image`, 1200×630 with
  `og:image:width/height/alt`) and Twitter `summary_large_image` cards
* JSON-LD: `OnlineStore` (name, address, phone, social profiles) and `WebSite`
  with a `SearchAction`, so Google can show the sitelinks search box
* icons: `/favicon.ico`, SVG, 32×32 PNG, 180×180 `apple-touch-icon` and a
  `manifest.webmanifest` for add-to-home-screen
* exactly one `<h1>` per page, landmark regions (`header`/`nav`/`main`/`footer`
  plus a labelled quick-help dock), labelled forms, and contrast-tuned greys

The share image falls back to `assets/img/og-cover.png`; the icon falls back to
the bundled mark. Both are replaced automatically once a logo/favicon is saved
in **Admin → Settings → General**.

Run `php tools/deploy-check.php` after uploading: it now also verifies that the
current release's metadata files and head/footer markup are present, and prints
the newest lines of `storage/logs/error-*.log`.

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

1. Create a MySQL/MariaDB database and database user in your hosting panel.
2. Import only [`database/schema.sql`](database/schema.sql) into that database with phpMyAdmin. It creates tables and defaults, but no accounts.
3. Copy `config/config.example.php` to `config/config.php` and enter the actual database credentials and app key.
4. Open the storefront registration page and create the first account. The first registration is made administrator; every later public registration is a customer and must verify its email.
5. Sign in to the dashboard, configure email delivery, and invite any team members from **Admin → Team**. The invitation email contains a temporary password that must be changed at first sign-in.


### Command line (VPS / local)

```bash
php tools/deploy-check.php                                  # verify the deployment
php tools/seed.php --products=10000 --customers=10000 --orders=250
php tests/run.php            # unit tests, no DB writes
php tests/run.php --db       # + database integrity checks
```

Optional demo customers can be added later with **Admin → Tools → Seed demo data**. There is no seeded administrator or default admin password; the first administrator chooses their own password during registration.

---

## Project layout

```
index.php  shop.php  category.php  product.php  cart.php  checkout.php
track.php  invoice.php  deals.php  brands.php  login.php  register.php …
admin/            dashboard (orders, returns, products, payments, settings, reports, tools …)
account/          customer account pages, including return requests and tracking
actions/          POST handlers (auth, cart, checkout, account, returns, review …)
api/              JSON endpoints (cart, wishlist, live-orders, search-suggest)
payment/          start / return / notify / simulate / proof
includes/         core: Database, Auth, Security, Csrf, models, services
assets/           Bootstrap, Icons, app CSS/JS (vendored, no CDN)
database/         schema.sql (only SQL file; tables and default settings)
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
* [docs/DATABASE.md](docs/DATABASE.md) — credentials checklist, all 27 tables,
  status pipeline, category tree, seeding, maintenance SQL
* [docs/EMAIL.md](docs/EMAIL.md) — SMTP setup in Admin settings, encrypted credentials, test delivery and verification workflow
* [docs/SETTINGS.md](docs/SETTINGS.md) — YourMarket branding, customizable store settings, existing-install schema upgrade and currency/locale caveats
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

Prepared statements everywhere (with backtick-quoted identifiers, because MariaDB
treats words such as `condition` as reserved), bcrypt cost 12, a global CSRF guard on every POST,
database-backed rate limiting, strict nonce-based CSP, HttpOnly/SameSite session
cookies with rotation, role → permission checks on every dashboard page,
audit logging, validated uploads and HMAC-verified payment callbacks. Full detail
and the launch checklist: [docs/SECURITY.md](docs/SECURITY.md).

Do not commit `config/config.php`, and rotate the seeded demo passwords before
going live.

Two checks protect the SQL layer: `php tools/deploy-check.php` (verifies the
uploaded files and runs the reserved-word audit) and `php tools/sql-audit.php`
(prints any hand-written statement that leaves a MySQL/MariaDB reserved word
unquoted — the cause of the `SQLSTATE[42000] … 1064` error that used to block
product saves).

---

## Status of the live deployment

| Item | State |
| --- | --- |
| Database credentials | configured (`sql306.hstn.me` / `mseet_43261025_softmymarket`) |
| SQL script | `database/schema.sql` — create/import the database schema |
| Domain | `softmymarket.hstn.me` — set `app.url` once SSL is active |
| Payment gateways | COD + bank transfer enabled; Seylan / PayHere / KOKO / Payzy / Mintpay await merchant credentials |
| SMTP | not configured — mail uses the host's sendmail |
| Demo data | optional, use Admin → Tools after setup |

Rotate the database password that was shared during setup. The first administrator account is created during the first public registration; there is no seeded administrator password.
