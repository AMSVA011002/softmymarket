# Transactional email and customer verification

New customer accounts remain pending until the customer confirms control of the supplied email address. The application sends a one-time verification link that expires after 24 hours. Until verification, the account cannot sign in, access account pages, or place account-based orders. After opening the link, the customer sees a successful confirmation page and clicks **Continue to the store** to sign in and return to the storefront.

## Configure delivery from the dashboard

1. Sign in as an administrator and open **Admin → Settings → Email**.
2. Choose **Authenticated SMTP**.
3. Enter the SMTP hostname, port, connection security, full mailbox username and password supplied by the mail provider. The recommended secure default is port **465** with **SSL/TLS**; use the provider's exact settings if they specify a different secure combination.
4. Set the From address to a mailbox authorised to send through that server. A matching From address and SMTP username is generally recommended. Optionally set a reply-to address.
5. Save settings, then click **Send test email to my admin address**. Confirm delivery in the inbox and spam folder before testing customer registration.

SMTP authentication is required. The settings UI does not prefill provider-specific hostnames, mailbox names, or credentials. Enter those values directly in the dashboard; do not put mailbox passwords in PHP source files or commit them to the repository. The SMTP password is encrypted before it is stored in the database and is never displayed again. Keep a secure backup of the application encryption key: changing it can make a previously stored SMTP password unreadable, in which case re-enter the password in Email settings.

The server must allow outbound SMTP and support the selected SSL/TLS mode. Shared-hosting providers may restrict outbound ports. A successful send means the SMTP/server transport accepted the message; it does not guarantee inbox placement. Check provider logs, spam folders and DNS sender-authentication records (SPF, DKIM and DMARC) if mail is delayed or rejected.

## Existing database upgrade

For an installation already in use, back up the database first, then import `database/migrations/20261028_email_verification.sql` once in phpMyAdmin. It adds only the verification-token table. Do not import `database/install.sql` into a populated database because the fresh-install script drops and recreates application tables. Fresh installations use the updated `database/install.sql`.

Existing active customer accounts are not forced through verification again. Only new registrations created after this feature is installed require confirmation. Pending accounts may request a fresh link at the confirmation page; the response does not reveal whether an address has a registered account.
