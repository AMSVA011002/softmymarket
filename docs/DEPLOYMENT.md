# Deployment guide — SoftMyMarket

SoftMyMarket is a plain PHP 8.1+ / MySQL application. It runs on any shared
hosting account (cPanel, Plesk, DirectAdmin) and on a VPS — no Composer, Node or
build step is required in production.

---

## 1. What the host must provide

| Item | Minimum | Notes |
| --- | --- | --- |
| PHP | 8.1 (8.2/8.3 recommended) | `match`, enums, readonly, `str_contains` are used |
| MySQL / MariaDB | 5.7 / 10.3+ | `utf8mb4`, InnoDB, `JSON` column type |
| Apache | 2.4 with `mod_rewrite` | `.htaccess` files ship with the app; Nginx notes below |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo` | the installer checks these |
| Disk | 200 MB + image cache | `storage/cache/images` grows with the proxied catalogue |
| Cron (optional) | 1 job | only for the housekeeping task described in §7 |

The installer (`/install/`) verifies every requirement on step 1 and blocks the
next step until they pass.

---

## 2. Upload the files

1. Download / clone the repository.
2. Upload **everything except** `config/config.php` (it does not exist yet) to the
   web root — e.g. `public_html/` or `public_html/shop/`.
   Keep the folder structure: `includes/`, `database/`, `storage/`, `admin/`,
   `install/`, `assets/`, `tools/` must stay siblings of `index.php`.
3. Do **not** upload `.git/`, `tests/` or `docs/`. They are blocked by
   `.htaccess` anyway (`RedirectMatch 404`), but leaving them out keeps the
   package small.

> **Sub-folder installs:** the app is written for a domain root. If you must run
> it under `https://example.com/shop/`, set `app.url` accordingly and add
> `RewriteBase /shop/` to `.htaccess`. A subdomain or its own domain is the
> supported layout.

---

## 3. Create the database

In cPanel → **MySQL Databases**:

1. Create a database, e.g. `softmymarket`.
2. Create a user with a strong password (20+ random characters).
3. Add the user to the database with **ALL PRIVILEGES** on that database only.
4. Note the host (`localhost` on nearly every shared host).

Write the details down — the installer asks for them:

```
DB host      : localhost
DB port      : 3306
DB name      : cpaneluser_softmymarket
DB user      : cpaneluser_smm
DB password  : <generated>
Table prefix : (leave empty)
```

A full credentials checklist lives in [DATABASE.md](DATABASE.md).

---

## 4. Install the schema

**Fastest path (no SSH, works on every host):** phpMyAdmin → select the
database → **Import** → `database/install.sql` → Import. That creates all 23
tables, the default settings and the first administrator
(`admin@softmymarket.hstn.me` / `SoftMy@Admin2026` — change it after signing in).
Then optionally import `database/demo-data.sql` for a small demo catalogue.

Verify the result in **Admin → Tools** (row counts, extensions, writable folders)
or with `php tools/deploy-check.php`.

**Alternative — the web installer.** Open `https://your-domain.lk/install/` and
complete the three steps:

1. **Requirements + configuration** — server checks, app URL, database details.
   The installer writes `config/config.php` and generates a 64-character
   `app.key` (used to sign reset tokens and encrypt gateway references).
2. **Schema** — imports `database/schema.sql` (23 tables, indexes and foreign
   keys) and shows progress.
3. **Administrator account** — name, email and a strong password, plus an
   optional **demo catalogue** (120 products, 40 customers, 25 orders).

When it finishes the installer writes `storage/installed.lock` and refuses to run
again. `install/` stays useful for diagnostics; if you want it gone, delete the
folder (the app does not depend on it). The shipped `.htaccess` already returns
404 for `install/index.php`, and **Admin → Tools → Lock the installer** creates
the same lock file without touching `.htaccess`.

### Manual alternative

```bash
cp config/config.example.php config/config.php   # then edit the values
mysql -u USER -p DB_NAME < database/schema.sql
php tools/seed.php --products=10000 --customers=10000 --orders=250
```

---

## 5. File permissions

```bash
find . -type d -exec chmod 755 {} \;      # directories
find . -type f -exec chmod 644 {} \;      # files
chmod 640 config/config.php               # contains the DB password
chmod 640 storage/installed.lock

# writable by PHP (use your host's PHP user — on shared hosting this is often
# the same account, but confirm with your provider):
chmod -R 775 storage
```

`storage/` contains `logs/`, `cache/`, `uploads/`, `sessions/` and `backups/`.
Each has a `.gitkeep` file so the folders survive version control.

---

## 6. HTTPS and cookies

1. Issue a free certificate (cPanel → **SSL/TLS Status** → AutoSSL, or Let's
   Encrypt), then force HTTPS: uncomment the redirect block in `.htaccess` and
   enable HSTS once you've confirmed the certificate renews.
2. `session.secure` must be `true` in `config/config.php` (the installer sets it
   when you tick "HTTPS enabled"). Sessions are `HttpOnly` + `SameSite=Lax`.
3. Update `app.url` to the canonical `https://` origin so emails and payment
   callbacks point at the right host.

---

## 7. Optional cron job

Nothing in the storefront depends on cron. Two optional housekeeping jobs keep
the session table and the image cache from growing forever — add them under
**cPanel → Cron Jobs**:

```cron
15 3 * * * find /home/USER/public_html/storage/sessions -type f -mtime +14 -delete
30 3 * * 0 find /home/USER/public_html/storage/cache/images -type f -mtime +30 -delete
```

Neither is required for the store to work.

---

## 8. Seeding 10,000 products and 10,000 customers

```bash
php tools/seed.php --products=10000 --customers=10000 --orders=250
```

* Batched inserts (400 rows per statement) — finishes in seconds to a couple of
  minutes depending on the host.
* `--force` adds more data to an existing catalogue; without it the seeder stops
  when products already exist.
* `--demo` inserts the small installer-sized set (120 / 40 / 25).
* Every seeded customer uses the password `Password123!` — change or delete the
  seeded accounts before going live (`admin/customers.php`).
* Shared hosts often cap `max_execution_time` at 30 s. If the CLI run is killed,
  split it: `--products=2500` four times with `--force`, then customers.

---

## 9. Payment gateways

Configure in **Admin → Settings → Payments**. Each gateway has an
`enabled / mode / merchant id / api key / secret` block and its own callback
URLs:

```
Callback : https://your-domain.lk/payment/notify.php?gateway=seylan
Return   : https://your-domain.lk/payment/return.php?gateway=seylan
```

Register those two URLs with the bank / KOKO / Payzy / Mintpay merchant portal.

* **Cash on delivery** is enabled out of the box (optional fee + maximum order
  value).
* **Bank transfer** ships with editable account details; customers upload a
  slip, finance verifies it in **Admin → Payments**.
* **Seylan / PayHere / KOKO / Payzy / Mintpay** are disabled until credentials are added.
  For PayHere, configure it in **Admin → Settings → Payments**. In the PayHere Merchant Portal, create exactly one Integration record for the store origin configured in `app.url`, and use the Merchant Secret issued for that same domain. Do not create duplicate Integration records. Select **Sandbox** during development and enter the sandbox credentials; checkout posts to `https://sandbox.payhere.lk/pay/checkout`. Complete sandbox testing before selecting **Live**: the dashboard warns and asks for confirmation because real charges may occur. Live mode always posts to the required `https://www.payhere.lk/pay/checkout` endpoint and fails closed if credentials are missing. The site uses `strict-origin-when-cross-origin`, which sends the store origin as the cross-domain Referer; do not replace it with `no-referrer`. Configure the notify URL shown in the dashboard (`/payment/notify.php?gateway=payhere`) in the PayHere Merchant Portal. The callback is accepted only when PayHere’s `md5sig`, merchant ID, order number, amount, currency and payment method all match the order; the browser return alone never marks an order paid.

  PayHere checkout also requires valid customer email/mobile and complete recipient/address/city details. These are validated and unsupported markup/control characters and emoji are removed before submission; a clear message is shown if required contact details are invalid. If PayHere reports an unauthorized request, verify the registered store origin, its matching Merchant Secret and the generated hash inputs. For PH-0022, confirm the live URL remains exactly the `www` endpoint above. Other “Something Went Wrong” errors should be checked against the outgoing required POST fields and customer data; payment request payloads are not logged with secrets.

After saving credentials, place a test order for each method and confirm the
order status becomes `PAID` and the admin ledger shows the transaction.

---

## 10. Mail

`config/config.php → mail.transport`:

* `mail` — uses the host's sendmail binary (works on almost every cPanel box).
* `smtp` — SMTPS 465 or STARTTLS 587 with username/password.

Set `from_email` to an address on your own domain (SPF/DKIM aligned) or mail
will land in spam. With `mail.log => true` every message is appended to
`storage/logs/mail.log`, which is the quickest way to debug delivery on shared
hosting.

---

## 11. Post-deploy checklist

- [ ] `/install/` completes and `storage/installed.lock` exists
- [ ] `config/config.php` is `640` and not reachable over HTTP
      (`curl -I https://site/config/config.php` → 404/403)
- [ ] HTTPS redirect works and `session.secure` is `true`
- [ ] Home, shop, category, product, cart, checkout, track and invoice pages load
- [ ] Admin login works; staff account sees a restricted menu
- [ ] A test order runs through: place → pay → admin marks paid → status timeline
- [ ] Bank-transfer slip upload appears in **Admin → Payments**
- [ ] Live tracking updates on `/track.php` and in **Admin → Live orders**
- [ ] Product images render (Google Drive links go through `/image.php`)
- [ ] Password reset email arrives
- [ ] `storage/logs/php-error.log` is empty after a walkthrough
- [ ] Delete or re-password the seeded demo accounts
- [ ] `app.debug` is `false`, `payments.*.simulate` is `false` for live gateways

---

## 12. Nginx (VPS) notes

`.htaccess` is ignored by Nginx. As a minimum:

```nginx
root /var/www/softmymarket;
index index.php;

location ~ ^/(config|includes|database|storage|tests|tools|docs|install/lib)/ {
    deny all;
    return 404;
}
location ~* \.(git|env|md|sql)$ { deny all; return 404; }

location / { try_files $uri $uri/ /index.php?$query_string; }

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_hide_header X-Powered-By;
    fastcgi_read_timeout 120;
}
```

Add the same security headers the `.htaccess` sets (`X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`).

---

## 13. Backups

* **Database:** cPanel → phpMyAdmin → Export (SQL, structure + data) or
  `mysqldump -u USER -p DB > backup.sql`. Schedule it daily; orders and
  customers cannot be regenerated.
* **Files:** archive the whole web root. `config/config.php` is the only
  file you must keep secret — it holds the DB password and `app.key`.
* Restoring `app.key` matters: signed password-reset and tracking links break if
  it is regenerated.

---

## 14. Updating the site

1. Back up the database and `config/config.php`.
2. Upload the new files over the old ones — never overwrite `config/config.php`.
3. If `database/schema.sql` changed, apply the delta manually (the installer only
   runs on an empty database).
4. Clear `storage/cache/` and reload the storefront.
