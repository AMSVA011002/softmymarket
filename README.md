# SoftMyMarket

A production-ready, single-vendor marketplace for Sri Lanka — **PHP 8.1 + MySQL +
Bootstrap 5**, no Composer, no build step, deployable to any shared hosting
account.

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

## Quick start

```bash
# 1. upload the project to your web root, then:
cp config/config.example.php config/config.php     # edit DB details
mysql -u USER -p DBNAME < database/schema.sql

# 2. create the admin account (or run the web installer instead)
#    https://your-domain.lk/install/

# 3. seed the catalogue
php tools/seed.php --products=10000 --customers=10000 --orders=250

# 4. run the checks
php tests/run.php            # unit tests, no DB writes
php tests/run.php --db       # + database integrity checks
```

Prefer the browser? Open `/install/` and follow the three steps: requirements +
database → schema import → admin account (with optional demo data).

Demo logins after seeding (change before going live):

| Role | Email | Password |
| --- | --- | --- |
| Admin | the address you set in the installer | your password |
| Customer (any seeded account) | `customer1.<hash>@example.lk` — see **Admin → Customers** | `Password123!` |

---

## Project layout

```
index.php  shop.php  category.php  product.php  cart.php  checkout.php
track.php  invoice.php  deals.php  brands.php  login.php  register.php …
admin/            dashboard (orders, products, payments, settings, reports …)
account/          customer account pages
actions/          POST handlers (auth, cart, checkout, account, review …)
api/              JSON endpoints (cart, wishlist, live-orders, search-suggest)
payment/          start / return / notify / simulate / proof
includes/         core: Database, Auth, Security, Csrf, models, services
assets/           Bootstrap, Icons, app CSS/JS (vendored, no CDN)
database/         schema.sql (single source of truth)
tools/            seed.php (catalogue + customers + orders)
install/          three-step web installer
storage/          logs, cache, uploads, sessions, backups (not web-readable)
docs/             deployment, database, security documentation
tests/            CLI test suite
```

---

## Documentation

* [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — shared-hosting deployment, HTTPS,
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

## Credentials the maintainer will supply

Before the final deployment the store owner provides:

1. MySQL host / port / database / user / password
2. Hosting control panel + SSH/SFTP access details
3. Domain + SSL status (for `app.url` and secure cookies)
4. Merchant credentials for Seylan, KOKO, Payzy, Mintpay (or confirmation to stay
   in COD + bank-transfer mode)
5. SMTP mailbox credentials for transactional email

Insert them through the installer or `config/config.php`; nothing else needs to
change.
