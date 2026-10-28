# SoftMyMarket — Product and Technical Specification

**Document purpose:** describe the application represented by the current repository, separating code-supported behavior from configuration, merchant integration, and hosting requirements. This is a source-code specification, not a live production audit. The actual deployment's private configuration, credentials, database contents, and runtime behavior have not been inspected.

## 1. Product summary and scope

SoftMyMarket is a single-store online retail application for browsing and selling a merchant-managed product catalogue. It includes a public storefront, guest and registered-customer shopping, order/payment workflows, and an administration area for store operations. The codebase uses PHP-rendered pages with JavaScript enhancements and a MySQL/MariaDB database.

It is **not** a multi-seller marketplace: there is no seller onboarding, seller portal, commission settlement, or seller-owned catalogue. The store operator is responsible for products, fulfilment, payment-provider accounts, customer service, and deployment.

The repository implements the application workflows described below. A feature being present in source does not mean it is enabled or operational in a particular installation. Payment gateways, email, scheduled cleanup, SSL, database access, URLs, and merchant settings require valid deployment configuration.

## 2. Actors and permissions

| Actor | Capabilities |
|---|---|
| **Shopper / guest** | Browse and search active catalogue items, inspect product details, use a session cart, apply eligible coupons, proceed through guest checkout when enabled, submit contact/newsletter forms, and track an order using its order details. Guest checkout is configurable and enabled by default in the example configuration. A guest must sign in to use account-only functions such as the saved wishlist and review submission. |
| **Customer** | All shopper capabilities, plus register/sign in/sign out, reset a forgotten password by email, manage profile and saved addresses, access account order history/details and notifications, save wishlist items, and submit product reviews for moderation. Account orders can be cancelled only when the applicable order state and cancellation rules permit it. |
| **Staff** | Sign in to the administration area and perform operational tasks permitted by the configured staff permissions. The code distinguishes staff from administrators; staff access is intentionally narrower and permission checks apply to individual admin areas/actions. Typical operational work includes order processing, catalogue or review work where granted, and customer-message handling. Exact permissions depend on the role/permission records in the installed database. |
| **Administrator** | Full store-management access, including team/permissions, store settings, catalogue and inventory, order/payment administration, customer management, moderation, reports, content/branding, audit records, and maintenance tools. Administrators should be limited to trusted operators. |

Registration creates a customer account; it does not provide public self-service staff, administrator, or seller registration. Password-reset email delivery requires working mail configuration. The registration handler marks the new account's email as verified in the current implementation; do not treat that as evidence that a mailbox challenge was completed.

## 3. Shopper-facing functionality

### 3.1 Storefront and catalogue

- Storefront pages display configurable store branding, navigation, featured/promoted content and product cards.
- Products support title/slug, SKU, description, price, stock, category/brand association, status, images, product attributes/specifications, shipping metadata, and warranty information where populated.
- Product pages present product information, image galleries, stock/availability and customer reviews. Reviews are submitted by signed-in customers and enter a moderation queue before publication; a customer may submit at most one review per product.
- The database models nested categories. Catalogue navigation supports a bounded rendering depth; data may be nested more deeply than the interface renders.
- Catalogue listing supports text search, category/brand and other available filters, sorting, and pagination. Search suggestions/live search are provided by the application; the UI's intended live-search threshold is three characters. Search is database-backed, not a separate search-engine service.
- Product and hero images are referenced by external URLs (including publicly accessible Drive share links when their URL is directly usable). Some image paths may be cached by application infrastructure. The merchant must ensure URLs are public, stable, and compatible with browser/CSP and host behavior. The app is not an image-hosting or Drive-permission management service.
- Public information pages include store/contact, help, and policy/legal content. The presence of a policy page does not implement an operational returns or dispute service.

### 3.2 Search, wishlist, and cart

- Search and catalogue filters narrow the available product listing; suggestions are intended to start after at least three characters.
- Signed-in customers can save products to a wishlist. Session wishlist/cart state is merged into account-backed state on sign-in where supported.
- A cart holds product quantities and exposes price, discount, shipping, tax, and total estimates. A cart may begin in a guest session and be retained in the database for a signed-in customer.
- Cart rendering checks current product availability, active status and stock. A stale cart line or a quantity above current stock is flagged and must be corrected before an order is placed.
- Checkout re-reads and locks product rows, validates current stock and prices, and recalculates the quote server-side. Browser-supplied totals are not authoritative. Successful order placement reserves/decrements inventory according to the order/payment flow; cancellation or a failed provisional payment attempt restores stock through the lifecycle service.

### 3.3 Price, coupon, tax, and shipping rules

The shared pricing calculation is used for cart, checkout, order creation and payment amount calculation. Current rules include:

- Subtotal is calculated from current database prices and quantities.
- A configured default shipping fee applies unless all items qualify for free shipping, the configured subtotal threshold is met, or an eligible coupon grants free shipping. Example defaults are a LKR 15,000 free-shipping threshold and LKR 450 base fee; installations may override them.
- A configured coupon discount is applied, subject to the coupon's validation rules, dates, usage limits, minimums and applicable products/categories/customers.
- Tax is a simple configurable percentage on subtotal after discount. It is not a jurisdiction-aware tax/VAT compliance engine.
- A configurable COD fee may be added to shipping for cash-on-delivery orders.
- Currency is configured at store level (the example/default is LKR); no multi-currency conversion or settlement service is included.
- Product weight is stored and included in quote metadata, but the current calculation does not call a carrier to price shipping from weight or destination.

### 3.4 Checkout and order lifecycle

Checkout collects customer contact details, a delivery address, optional billing address, optional customer note/coupon, and a currently enabled payment method. Account checkout can use saved addresses; guest checkout depends on the `allow_guest_checkout` setting.

Orders store a snapshot of customer, address, item, price, discount, tax, shipping, currency and payment information. The order state machine currently supports:

`PENDING → CONFIRMED → PROCESSING → PACKED → SHIPPED → OUT_FOR_DELIVERY → DELIVERED`

Permitted cancellation/return/refund transitions are constrained by the implemented state machine. Each order can have a chronological event history, with events that may be public for customer tracking or internal for staff. Administrators/staff can add operational notes and manually update fulfilment/tracking information according to their permissions. Customers can view order details and tracking events; a printable invoice is available through the web interface (browser print/save-to-PDF, not a dedicated PDF-generation service).

Guest order lookup uses order-identifying customer details. Account holders can see their eligible placed orders in account history. A browser return from an online gateway is not, by itself, authoritative evidence of payment; payment confirmation must come through the verified server-side gateway callback path.

### 3.5 Payment methods and status

The payment manager contains handlers for:

| Method | Current repository behavior | Operational requirement |
|---|---|---|
| Cash on delivery (COD) | Local order method; the example configuration enables it by default. The order is unpaid until staff records the relevant fulfilment/payment event. | Merchant must actually offer and collect COD. Configure fee/availability and train fulfilment staff. |
| Manual bank transfer | Local instructions/reference and receipt-upload/verification workflow; enabled by default in the example configuration. Payment remains unverified until staff reviews and records it. | Merchant must enter correct bank instructions, securely handle uploaded proof, and reconcile the bank account. A reference number alone is not proof of receipt. |
| Seylan hosted payment, PayHere, KOKO, Payzy, Mintpay | Gateway integrations/handlers are present, but these five methods are **disabled** in the example configuration. Their presence does not mean a real merchant account or live transaction is available. | Appropriate merchant approval, credentials/secrets, exact provider setup, live/test mode choices, callback/return URLs, domain/SSL configuration and provider-side activation are required. Validate each provider's current integration requirements before production use. |

For online redirect flows, the order is provisional while payment is pending. The application expects a verified server-side success notification; failed/expired attempts are subject to cleanup and stock restoration. Gateway payloads and verification are provider-specific; generic handler availability must not be represented as proof that each provider's production API has been independently validated. Do not enable a gateway until test transactions, callback verification, amount/currency matching and failure/retry behavior have been operationally checked.

### 3.6 Bank-transfer receipt expiry and inventory

A bank-transfer order without an uploaded receipt is automatically cancelled after **one hour**, and reserved stock is returned. A receipt reference alone does not satisfy the receipt-upload requirement. Cleanup is traffic-triggered with throttling and a recommended CLI/cron fallback at approximately five-minute intervals. Without site traffic and without the scheduled task, cleanup can be delayed. Orders with an uploaded receipt are not subject to this no-receipt expiry rule and require staff review.

Separate stale online-gateway payment attempts are cleaned up by the order/payment lifecycle (the current code uses a longer, approximately 24-hour expiry for abandoned gateway attempts). These timeouts are not a substitute for a reliable production scheduler and monitoring.

## 4. Customer account and communications

Account pages cover profile/settings, saved delivery addresses, order list/details, wishlist, notifications, password-reset flows, and customer return requests. A signed-in customer can select eligible order lines and quantities, choose a reason, describe the issue, optionally attach photo/PDF evidence, cancel an unprocessed request, add return-shipment tracking after approval, and follow the return timeline. Notification records are in-app notifications; they are not mobile push notifications or SMS. Welcome/reset email and other mail-dependent messages require a correctly configured SMTP or host mail transport.

The application includes contact/message intake and newsletter subscription capture. It does not include a full email campaign platform or automated marketing-delivery service. Reviews are moderated before display. Customer support handling, delivery arrangements, return parcel booking, physical inspection, and the actual movement of refund money remain merchant-operated processes. Return requests are account-only and currently limited to seven days after an order is marked delivered; damaged/wrong-item reports additionally require a photo and must be filed within 48 hours. Return quantities are validated against order lines and other active/completed returns; rejected or cancelled requests release those quantities for a new request. Product-category exclusions and item condition are not encoded as per-product database rules, so staff must apply the published policy during review. Evidence is stored in protected application storage and served only to the owning customer or authorised store staff.

## 5. Administration and store operations

The admin area contains source-supported modules for:

- Dashboard and order queue/live order board.
- Product, category, brand and inventory management, including product activation/status and stock edits.
- Order search/filtering, status transitions, customer/order notes, payment records and manual bank-transfer receipt verification.
- Return-request queue and case workspace: approve/reject requests, record receipt/inspection, add customer-facing updates, record a manually processed refund or exchange, and optionally restock received units with an inventory movement entry. Refund/exchange outcomes do not initiate external payment or replacement delivery.
- Manual tracking details and public/internal order events.
- Coupon creation and eligibility/usage management.
- Review moderation and customer management/status actions.
- Staff/team accounts and permission management.
- Customer messages, reports/summary metrics and CSV export where provided.
- Store settings, branding, navigation/hero slides and content settings.
- Audit history and administrative maintenance/installer utilities.

Role-based restrictions apply; a module appearing in the admin application does not imply every staff account can use it. Refund-related statuses/records are administrative bookkeeping. No code-supported automated transfer of funds back to a card, wallet, or bank account is implied.

## 6. Architecture and data

### 6.1 Application architecture

- Server-rendered PHP pages and actions; reusable application logic lives in `includes/` and the public, account, admin, API and payment routes call those services.
- Browser enhancements use JavaScript, CSS and Bootstrap; vendored front-end assets are included in the repository.
- MySQL/MariaDB access uses the application's database layer and PDO-style parameterized queries. Database identifiers and reserved words must be compatible with MariaDB/MySQL.
- No Composer or Node build is required for the documented deployment path.
- Payment, email, image URL and cron behavior are external integrations/operational dependencies, not self-contained services.

### 6.2 Database

The current documented schema contains 27 tables covering users/roles, catalogue, categories/brands, carts/wishlists, orders/order items/events/payments, return requests/items/events, email-verification tokens, coupons, reviews, addresses, notifications, contact/newsletter records, password resets, settings and audit/operational data. See `docs/DATABASE.md` for entity-level descriptions and `database/schema.sql` for the canonical schema. `database/install.sql` is the deploy/import-oriented SQL file supplied for creating the database tables and initial setup data; inspect the exact file for the current import contents before deployment.

Install the schema into a new database or take a verified backup and follow the project's upgrade guidance before applying schema changes to an existing store. Do not assume importing an install script into a populated database is non-destructive. Existing installations predating return requests can apply `database/migrations/20261028_returns.sql` once; it creates only the three new return tables.

## 7. Configuration and deployment requirements

### 7.1 Configuration

Copy the example configuration to the private runtime configuration file and enter correct database, store, mail, URL, security and gateway settings. The production configuration file contains secrets and must not be committed or exposed publicly. The code should be deployed with the actual host URL and a strong application secret; placeholder or mismatched values can break URL generation, sessions, callbacks or cryptographic operations even if some pages appear to load.

The example configuration enables **COD and bank transfer**. Other listed gateways default disabled. Never infer a deployment's active gateway list solely from this document or the example file: inspect the private runtime configuration and merchant account setup.

The deployment must also configure a writable, protected location for required runtime/cache/upload data; correct PHP/database versions and extensions; database credentials with least-privilege access; HTTPS; and correct file permissions. User-upload handling and publicly reachable static files must follow the repository's deployment and security guides.

### 7.2 Hosting

The project documentation targets conventional PHP hosting with MySQL/MariaDB, including the stated AeonFree-style shared-hosting environment. Verify the host's PHP version/extensions, MariaDB version, HTTPS support, upload limits, rewrite support, outbound mail capability, cron availability, and filesystem permissions before launch. The application does not provision its own host, database, domain, SSL certificate, mail service or payment-provider account.

For a deployment such as `softmymarket.hstn.me`, enter the exact HTTPS public URL in configuration when TLS is active, and use the host-provided MariaDB database host/name/user/password. Do not assume the development/example host name or a local URL is valid in production. The database host is distinct from the public web host where the provider specifies it.

### 7.3 Scheduled work and operations

Configure a five-minute scheduled invocation of the provided cleanup task where the host supports cron, particularly for bank-transfer receipt expiry and abandoned payment cleanup. The web-traffic cleanup path is a fallback, not a reliable scheduler. Back up the database and any application-managed upload/cache data, monitor PHP/application logs and payment callbacks, and regularly test restore procedures. Maintenance/installer tools should be disabled or access-restricted after installation as directed by deployment documentation.

## 8. Security and privacy requirements

Security mechanisms in source include authentication and role checks, password hashing, session protections, CSRF verification for state-changing requests, rate limiting on sensitive flows, server-side validation/repricing, parameterized database queries, signed/verified payment callbacks, password-reset tokens stored in hashed form, audit events, and output escaping helpers. These controls reduce risk but do not constitute a security certification or guarantee that a deployment is secure.

Production operators must:

1. Use HTTPS site-wide and keep PHP, database and host software supported and patched.
2. Set a strong unique application secret and unique database credentials; grant the app only required database privileges.
3. Keep runtime config, backups, logs, uploaded receipts, and private data outside public access where possible; apply least-privilege filesystem permissions.
4. Configure exact trusted URLs and payment callbacks; validate gateway signatures, merchant IDs, amounts, currency, duplicate notifications and idempotency in provider test/live environments.
5. Protect administrative accounts, use unique strong passwords, grant minimal staff permissions, and remove initial/demo credentials and installation access.
6. Configure mail securely; avoid logging secrets, payment data or unnecessary personal information.
7. Set backup retention, access controls and tested recovery procedures consistent with applicable privacy and payment obligations.

No claim is made here that the application is PCI-certified, legally compliant in a particular jurisdiction, penetration-tested, or production-secure merely because these controls exist in source.

## 9. Testing and verification

The repository includes a PHP CLI test runner at `tests/run.php`. Run it using the documented PHP CLI command; optional database checks require a configured test database and must not target production. Review `tests/` and the test runner for exact coverage and required environment variables.

Automated tests are not a substitute for deployment acceptance testing. At minimum, test guest and account checkout, stock races/stale carts, coupon and price calculations, cancellation/restock paths, one-hour bank-transfer expiry, receipt upload/verification, each enabled payment gateway's success/failure/duplicate callback behavior, mail delivery, admin-role restrictions, backups/restores, and mobile/desktop layouts on the actual host. The source inspection for this specification did not execute PHP tests or a live database/payment transaction.

## 10. Supported now versus unavailable or externally dependent

### Supported by application code (subject to valid installation/configuration)

- Single-store catalogue, categories/brands, product pages, search/filtering, customer cart/wishlist and coupons.
- Guest/account checkout, address and order records, order lifecycle/tracking events, printable invoice, inventory checks and stock restoration paths.
- COD and manual bank-transfer workflows; one-hour cancellation for bank-transfer orders without a receipt.
- Customer accounts, password reset, saved addresses, in-app notifications, moderated reviews, and a 7-day customer return request/review lifecycle.
- Staff/admin workflows for store operations, reports, content/settings, audit and maintenance.

### Requires merchant credentials or operational setup

- Real transactions through any online gateway; listed handler code is not proof of provider approval, configured credentials or production-tested callbacks. In the example configuration, all five online gateways are disabled.
- Email/reset delivery: requires valid SMTP/host mail settings and working outbound delivery.
- Automated expiry timing: requires the scheduled cleanup task for predictable behavior; traffic-based cleanup alone may run late.
- Correct public URLs, HTTPS, database access, writable protected storage, initial admin setup, backups, and actual stock/catalogue data.
- Actual physical delivery, COD collection, bank reconciliation, manual payment approval, customer support and refunds.

### Not provided as a built-in capability

- Multi-vendor/seller marketplace, seller onboarding/portals, commissions, seller payouts, or split settlements.
- Carrier rate-shopping, label purchase/printing, dispatch booking, or automatic carrier tracking. Shipment tracking is entered/maintained manually.
- Automated gateway refunds, chargeback/dispute management, carrier booking for return parcels, or automatic replacement-order fulfilment. The app now provides a customer RMA request and staff review/inspection workflow, but staff must arrange return shipping, process refunds outside the app, and fulfil exchanges separately. Recording a refund or exchange does not itself send money or create a replacement order.
- ERP, accounting, procurement/supplier purchasing, POS, warehouse management, barcode/serial/lot tracking, or external inventory synchronization.
- Product option/variant matrix management, subscriptions/recurring billing, stored payment methods, or multi-currency conversion.
- Dynamic zone-based shipping quotes or tax jurisdiction/VAT calculation and filing.
- Native iOS/Android app, offline storefront, SMS/WhatsApp delivery, or mobile push notifications.
- A full marketing-campaign/email automation system, dedicated search-engine service, or dedicated PDF-generation service.

These boundaries are based on inspected source and documented schema; they should be revisited if new integrations or modules are added.

## 11. Reference files

- `README.md` — project overview and quick start.
- `config/config.example.php` — example settings and default enabled/disabled methods.
- `database/schema.sql` and `database/install.sql` — canonical schema and import/install artifact.
- `docs/DATABASE.md` — data model.
- `docs/DEPLOYMENT.md`, `docs/AEONFREE.md` — deployment guidance.
- `docs/SECURITY.md` — security and operational guidance.
- `tests/run.php` and `tests/` — CLI test entry point and checks.
