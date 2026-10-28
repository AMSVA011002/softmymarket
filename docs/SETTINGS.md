# Store identity and dashboard customization

The software ships with **YourMarket** as its neutral brand name. Store identity,
storefront content, visual identity, commerce defaults, payment providers and
transactional email can be changed in **Admin → Settings**; merchants should not
need to edit PHP files for these values.

## Store settings

- **Store** — name and tagline, description, logo and favicon, social-share
  image, primary/dark/accent colours, currency code and display symbol, language
  tag, address/locality/country, business hours, support and order-notification
  email/phone, announcement bar, footer note, social URLs, page metadata, and
  analytics identifiers.
- **Commerce** — shipping fees and free-shipping threshold, tax percent, low
  stock threshold, order-number prefix, guest checkout, reviews, maintenance
  mode, live-tracking refresh interval and a configurable return-request window
  (1–90 days; damaged/wrong-item reports retain the separate 48-hour rule).
- **Content** — About, Shipping, Returns, Terms and Privacy copy, custom help
  FAQs, bank-transfer and tracking-page text, typical delivery promise, support
  response-time text, and every heading/caption in the storefront trust strip.
- **Email** — authenticated SMTP, sender/reply-to information, encrypted
  password retention and a test-send action. See [EMAIL.md](EMAIL.md).
- **Payments** — gateway enablement, sandbox/live options, provider credentials,
  supported account details and customer instructions.
- **Hero slides** — slide copy, image URLs, call-to-action labels/links and
  themes.

External logo, favicon and image URLs are entered through the dashboard. They
are proxied and cached by the existing image service. Social and analytics
fields are optional and disabled when left blank. Analytics IDs are validated;
Meta Pixel is only emitted for a valid numeric Pixel ID.

### Currency and locale

Changing the currency code and display symbol changes the storefront labels and
formatted price symbols. It **does not convert product, shipping, coupon or
order amounts**; those amounts remain stored in the database's current currency.
Only change the currency after planning the price migration. The language tag
(e.g. `en-LK`) controls the HTML language and search/share metadata; it does not
translate the application interface. The country code is ISO 3166-1 alpha-2.

### Site URL and hosting values

The public application URL, database credentials, encryption key, PHP timezone,
and session/security settings remain in `config/config.php` because they affect
routing and security. Set the exact deployed HTTPS URL there. Set the actual public HTTPS URL and database credentials provided by your hosting provider. These vary by deployment and must not be copied from sample values in documentation.

## Schema and brand defaults

Import the single `database/schema.sql` file into the manually created database. Re-importing it is safe for existing tables and custom settings. It creates missing tables, seeds only absent setting keys, and updates only values equal to known shipped SoftMyMarket defaults, preserving customized values. Afterward, open **Admin → Settings → Store** and review the placeholder contacts, address, social URLs, currency, SEO and legal text before launch. This schema update does not change your domain, database name, user accounts or custom logo/content.
