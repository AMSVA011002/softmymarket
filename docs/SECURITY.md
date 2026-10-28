# Security model — YourMarket

The store handles customer accounts, addresses and payment proofs, so the app
treats every request as untrusted until it proves otherwise. This page documents
what is implemented and where to find it, so the behaviour can be reviewed and
tested rather than taken on faith.

---

## 1. Defence-in-depth overview

| Threat | Control | Where |
| --- | --- | --- |
| SQL injection | PDO prepared statements, allow-listed `ORDER BY`, quoted identifiers | `includes/Database.php`, `includes/Catalog.php` |
| XSS | `e()` on output, JSON-in-attribute helper, strict CSP with per-request nonce | `includes/helpers.php`, `includes/Security.php` |
| CSRF | Global POST guard + per-handler verification, rotating token ring | `includes/Csrf.php`, `enforce_csrf()` |
| Brute force | Database-backed rate limiter per bucket + IP/email | `includes/RateLimiter.php` |
| Session hijacking | Regenerated IDs, HttpOnly/SameSite/Secure cookies, idle timeout, remember-me fingerprint | `includes/Session.php`, `includes/Auth.php` |
| Privilege escalation | Role → permission map, `admin_require()` on every dashboard page | `includes/Auth.php`, `admin/includes/bootstrap.php` |
| Mass assignment | Validator drops unknown keys before writes; `Users::WRITABLE` allow-list | `includes/Validator.php`, `includes/Users.php` |
| Password cracking | bcrypt cost 12, automatic rehash on login, policy check | `includes/Auth.php` |
| IDOR | Orders/products addressed by UUID or ownership checks | `includes/Orders.php` (`ownedBy`) |
| SSRF (image proxy) | Host allow-list, scheme check, size + type limits, no redirects to private IPs | `image.php` |
| Malicious uploads | MIME sniffing, extension allow-list, random filenames, stored outside the doc root, `Content-Disposition` | `includes/Uploads.php`, `payment/proof.php` |
| Forged gateway callbacks | HMAC verification before any state change; callbacks idempotent | `includes/HostedGateway.php`, `payment/notify.php` |
| Enumeration | Uniform responses on login/forgot-password, rate limits | `actions/auth.php` |
| Information leakage | `display_errors` off, generic error page, `X-Powered-By` removed, logs outside the doc root | `includes/bootstrap.php`, `.htaccess` |

---

## 2. SQL injection

* Every query uses `?` or named placeholders. Values never touch the SQL string.
* Sorting and filtering are **allow-listed**:

  ```php
  // includes/Catalog.php
  private static function orderBy(string $sort, string $term = ''): string
  {
      return match ($sort) {
          'price_asc'  => 'p.price ASC, p.id DESC',
          ...
          default      => 'p.created_at DESC, p.id DESC',
      };
  }
  ```

  An unknown `?sort=` value falls back to `newest`; it is never interpolated.
* Dynamic identifiers (table/column names for the admin import tools) go through
  `Database::identifier()` which rejects anything that is not
  `^[a-z][a-z0-9_]{0,62}$`.
* PDO runs with `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false`, so the
  MySQL driver performs real server-side prepares.
* Not exposed: no `mysqli_query`, no string-concatenated `WHERE` built from
  `$_GET`. `tests/run.php` and the static lint step in CI catch raw interpolation
  of request data.

---

## 3. Cross-site scripting

* All dynamic output is escaped with `e()` (`htmlspecialchars(..., ENT_QUOTES)`
  with `UTF-8`). Rich text is never rendered raw from user input; product
  descriptions are escaped and line breaks are converted with `e_nl2br()`.
* JSON embedded in attributes uses `json_attr()`.
* The Content-Security-Policy is strict and nonce-based:

  ```
  default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self';
  object-src 'none'; img-src 'self' data: blob: https:;
  script-src 'self' 'nonce-<per-request>' https://www.googletagmanager.com;
  connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com
  ```

  Google Tag Manager is only allowed because the analytics snippet is optional;
  every other script must be same-origin **and** carry the nonce. There are no
  inline `onclick`/`onsubmit` handlers anywhere in the code base — behaviours are
  attached with `data-*` attributes in `assets/js/app.js`.

  Inline scripts must carry `nonce="<?= e(Security::nonce()) ?>"`. No CDN is
  required — Bootstrap is vendored under `assets/vendor/`.
* `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: strict-origin-when-cross-origin` and HSTS (on HTTPS) ship on
  every response.

---

## 4. CSRF

```php
// includes/Csrf.php
public static function check(?string $token): bool   // constant-time compare
public static function field(): string               // <input type="hidden" name="_token">
public static function verifyRequest(): void         // 419 page on failure
```

* `includes/bootstrap.php` calls `enforce_csrf()` for **every POST**, before any
  page code runs. Forgetting `verifyRequest()` in a new handler therefore cannot
  open a hole; endpoints must opt out explicitly via `csrf_exempt_paths()`.
* Only three paths are exempt, and each authenticates its caller differently:
  `/payment/notify.php` and `/payment/return.php` (HMAC signature) and
  `/install/index.php` (its own token check).
* Tokens are per session, rotated, kept in a 12-entry ring so multiple tabs work,
  and compared with `hash_equals`.
* The token is also accepted from `X-CSRF-Token` for the JSON API endpoints
  (`api/cart.php`, `api/wishlist.php`, `api/notifications.php`).

---

## 5. Authentication and sessions

* Passwords: `password_hash(PASSWORD_DEFAULT, ['cost' => 12])`, verified with
  `password_verify()`; `password_needs_rehash()` upgrades old hashes on login.
  Policy: 8+ characters with letters and numbers (`Auth::passwordErrors()`).
* Login attempts are rate limited per IP **and** per email
  (`login`: 5 tries / 15 min) with the same generic error message for wrong
  password and unknown account.
* Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS,
  `session.use_strict_mode`, `session.use_only_cookies`.
* The session id is regenerated at login and periodically
  (`security.session_regenerate`, default 900 s) and destroyed on logout.
* Remember-me is a signed cookie bound to a fingerprint of the password hash, so
  changing the password invalidates every device.
* Idle timeout: 2 hours (`session.idle`), absolute lifetime 14 days.
* Staff and customers are separated by role, not by separate tables:
  `Auth::isTeam()` guards `/admin/`; `Auth::requireLogin()` guards `/account/`.
* The first public registration is granted administrator access under a MySQL
  advisory lock so concurrent sign-ups cannot create multiple first admins.
  Register that account immediately after importing `database/schema.sql`;
  registration automatically writes `storage/installed.lock` when possible.
* Team invitations receive random temporary passwords by email. A
  `must_change_password` flag gates every request except the password-change
  screen and logout until the team member replaces it. Initial login does not
  create a remember-me cookie.

---

## 6. Authorisation

```php
'admin'    => ['*'],
'staff'    => ['dashboard.view','products.manage','inventory.manage','orders.view',
               'orders.manage','customers.view','customers.manage','reviews.manage',
               'payments.verify','messages.view','liveboard.view','coupons.view',
               'coupons.manage','analytics.view','settings.view'],
'customer' => ['account.self'],
```

* `admin_require('permission')` is the first statement of every dashboard page and
  of every branch in `admin/actions.php`. A staff member without
  `users.manage` or `settings.manage` never sees those menu entries and gets a
  403 if they type the URL.
* Admin-only actions are additionally audit-logged with actor, IP and user agent.
* The last remaining administrator cannot be deleted or demoted
  (`Users::isLastAdmin()`).
* Customers can only read their own orders (`Orders::ownedBy()`); public tracking
  needs the order number **and** the signed tracking token/uuid.

---

## 7. Rate limiting

Database-backed so it works on shared hosting without Redis:

| Bucket | Limit | Window |
| --- | --- | --- |
| `login` | 5 | 15 min |
| `register` | 5 | 1 h |
| `forgot` | 4 | 1 h |
| `checkout` | 10 | 10 min |
| `coupon` | 20 | 10 min |
| `track` | 20 | 10 min |
| `review` | 10 | 1 h |
| `contact` / `newsletter` | 5 | 1 h |
| `search` | 120 | 1 min |
| `api` | 240 | 1 min |
| `payment` | 30 | 10 min |

Identifiers are hashed before storage, windows expire automatically and stale
rows are pruned opportunistically. Clearing a lockout:
`DELETE FROM rate_limits WHERE bucket = 'login';`

---

## 8. Payments

* The app never stores card data. Card payments leave the site through the
  bank's hosted page; only a reference and status are kept in `payments`.
* Callback signatures are verified **before** any status change
  (`HostedGateway::verifyCallback()`), and callbacks are idempotent — a repeated
  notification cannot mark an order paid twice.
* Bank-transfer proofs are validated uploads (JPEG/PNG/WebP/PDF, ≤5 MB),
  renamed randomly, stored under `storage/uploads/` (outside the web root,
  blocked by `.htaccess` and served through `payment/proof.php` after an
  ownership/staff check).
* `simulate => true` is a demo mode. Set it to `false` for every gateway before
  taking real orders, and keep `mode` on `sandbox` until the merchant account is
  approved.
* Gateway secrets edited in the dashboard are written to the `settings` table in
  one JSON document; blank password fields keep the saved value. They are never
  printed back to the browser.

---

## 9. Product images and the proxy (`image.php`)

* Accepts Google Drive share links (`drive.google.com/file/d/…`, `?id=…`,
  `lh3.googleusercontent.com/…`) and plain `https://` URLs from an allow-list of
  hosts (`images.allowed_hosts` in `config/config.php`).
* Rejects any other scheme (`file://`, `php://`, `data:`), oversized responses
  (`images.max_bytes`, 5 MB default) and non-image content types.
* Responses are cached under `storage/cache/images/` with a 7-day TTL and sent
  with `Content-Type: image/*`, `X-Content-Type-Options: nosniff` and a long
  `Cache-Control`.
* A placeholder SVG is returned when the remote host fails, so a broken Drive
  link never breaks a product card.

---

## 10. Uploads

`Uploads::store()` validates:

1. `is_uploaded_file()` (no path injection),
2. PHP upload error codes and `uploads.max_bytes`,
3. the real MIME type via `finfo` against the allow-list,
4. the extension derived from the detected type (never from the filename),
5. a random filename (`Ymd-<16 hex>.<ext>`), `0644` permissions, storage outside the web root.

Payment proofs are served only through `payment/proof.php`, which enforces
login + ownership (or staff permission).

---

## 11. Logging and audit

* `audit_log` records every administrative change: `user_id`, `action`,
  `entity`, `entity_id`, `meta` (JSON), `ip_address`, `user_agent`, timestamp.
  View and filter it in **Admin → Audit log** (permission `settings.manage`).
* PHP errors go to `storage/logs/php-error.log`; mail is optionally logged to
  `storage/logs/mail.log`. Both live outside the web root and are git-ignored.
* `display_errors` is off, `expose_php` is off, uncaught exceptions return a
  generic page with a reference code, never a stack trace.

---

## 12. Files that must never be public

`.htaccess` blocks `config/`, `includes/`, `database/`, `storage/`, `tests/`,
`tools/`, `docs/`, dotfiles and `*.sql`/`*.md` at the root. Each of those
directories also ships its own `Require all denied`. If you deploy to Nginx, copy
the deny rules from [DEPLOYMENT.md §12](DEPLOYMENT.md#12-nginx-vps-notes).

---

## 13. Hardening checklist before launch

- [ ] `app.debug = false`, `app.env = production`
- [ ] `app.key` is 64 random hex characters, backed up
- [ ] `session.secure = true`, HTTPS forced, HSTS enabled
- [ ] Database user is not `root` and has no `GRANT`/`FILE` privileges
- [ ] All `payments.*.simulate` set to `false`; sandbox `mode` switched to `live`
      only after merchant approval
- [ ] Seeded demo customers/orders removed or passwords rotated
- [ ] `storage/installed.lock` exists; `/install/` returns 404 or is deleted
      (the shipped `.htaccess` already returns 404 for `install/index.php`, and
      **Admin → Tools → Lock the installer** writes the lock file)
- [ ] Database password rotated after setup (it was shared in plain text) and
      `config/config.php` is 0640 — it is git-ignored on purpose
- [ ] First administrator was registered immediately after importing the schema
- [ ] Team invite temporary passwords are changed at first sign-in
- [ ] `robots.txt` disallows `/admin/`, `/account/`, `/payment/` (shipped)
- [ ] `curl -I https://site/config/config.php` → 403/404
- [ ] Cron/backup job configured; restore tested once
- [ ] `php tests/run.php --db` passes on the production database

---

## 14. Reporting a vulnerability

Email `security@yourmarket.example` with reproduction steps and impact. Please do not
open a public issue for a live vulnerability. Rotate `app.key` and all gateway
secrets immediately if you suspect a credential leak.

---

## 15. Known limits (by design, for shared hosting)

* No WAF, no Redis, no queue. Rate limits and caches are database/file based.
* Payment gateways use hosted redirects, not server-side card APIs — that keeps
  the store out of PCI scope entirely.
* The audit log is append-only but stored in the same database; for stronger
  guarantees ship it to an external log sink (`storage/logs` cron + rotation).
