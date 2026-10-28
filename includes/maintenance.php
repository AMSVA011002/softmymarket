<?php
declare(strict_types=1);

/**
 * Storefront maintenance / holding page.
 *
 * Triggered from includes/bootstrap.php when Settings → Commerce → Maintenance
 * mode is on. Deliberately self-contained: the markup carries its own CSS and
 * uses only settings that are already loaded, so the page still renders before
 * the catalogue, cart or any other subsystem is touched.
 *
 * What stays reachable while maintenance is on:
 *   - the dashboard (/admin/*), where the team can switch the store back on
 *   - login/logout; only the login POST is allowed through the shared auth
 *     action (registration and password changes remain blocked)
 *   - static assets, the image proxy and payment callbacks
 *   - this page itself
 */

/**
 * Is the current request a path that maintenance mode must not block?
 *
 * @param list<string> $exempt path prefixes that are always allowed
 */
function smm_maintenance_exempt(string $path, array $exempt = []): bool
{
    $path = '/' . ltrim($path, '/');
    $defaults = [
        '/admin',
        '/login.php',
        '/logout.php',
        '/forgot-password.php',
        '/reset-password.php',
        '/maintenance.php',
        '/assets/',
        '/image.php',
        '/payment/notify.php',
        '/payment/return.php',
        '/install/',
        '/tools/',
        '/robots.txt',
        '/sitemap.php',
    ];
    foreach (array_merge($defaults, $exempt) as $prefix) {
        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
            return true;
        }
    }
    // Exact-file prefixes (e.g. /login.php) also need a plain equality check,
    // which the first condition above already covers.
    return false;
}

/**
 * Allow only the login mutation through the maintenance gate. The shared auth
 * action also handles registration and password changes, which remain closed.
 */
function smm_maintenance_login_allowed(string $path, string $method, string $action): bool
{
    $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
    return $path === '/actions/auth.php'
        && strtoupper($method) === 'POST'
        && $action === 'login';
}

/**
 * Render the holding page and stop.
 *
 * Pure output, no redirect: the status code belongs to the gate.
 */
function smm_maintenance_page(): never
{
    $brand = [
        'name'   => 'SoftMyMarket',
        'logo'   => '',
        'color'  => '#0c800f',
        'dark'   => '#0a6510',
        'accent' => '#0a57a0',
    ];
    $email = '';
    $phone = '';
    $whatsapp = '';
    $address = '';
    $tagline = '';

    try {
        $brand = Settings::brand();
        $email = trim(Settings::get('support_email'));
        $phone = trim(Settings::get('support_phone'));
        $whatsapp = trim(Settings::get('support_whatsapp'));
        $address = trim(Settings::get('address'));
        $tagline = trim(Settings::get('store_tagline'));
        if ($whatsapp !== '') {
            $whatsapp = preg_replace('/[^0-9+]/', '', $whatsapp) ?? '';
        }
    } catch (Throwable $e) {
        error_log('[smm] maintenance page settings unavailable: ' . $e->getMessage());
    }

    $storeName = $brand['name'] !== '' ? (string) $brand['name'] : 'SoftMyMarket';
    $initials = initials($storeName, 2);
    $brandColor = (string) ($brand['color'] ?: '#0c800f');
    $brandDark = (string) ($brand['color_dark'] ?: '#0a6510');
    $accent = (string) ($brand['accent'] ?: '#0a57a0');
    $logoHtml = '<span class="mnt-mark">' . e($initials) . '</span>';
    if (!empty($brand['logo'])) {
        $logoHtml = '<img class="mnt-logo" src="' . e(img_url((string) $brand['logo'], $storeName, 320)) . '" alt="' . e($storeName) . '" height="46">';
    }
    $faviconHtml = !empty($brand['favicon'])
        ? '<link rel="icon" href="' . e(img_url((string) $brand['favicon'], $storeName, 128)) . '">'
        : '<link rel="icon" href="' . e(asset('assets/img/favicon.svg')) . '">';

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 900');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
    }
    ?>
<!doctype html>
<html lang="en-LK">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>We'll be back shortly · <?= e($storeName) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="<?= e($brandColor) ?>">
  <?= $faviconHtml ?>
  <style>
    :root {
      --brand: <?= e($brandColor) ?>;
      --brand-dark: <?= e($brandDark) ?>;
      --accent: <?= e($accent) ?>;
      --ink: #0f172a;
      --muted: #5b6777;
      --card: rgba(255, 255, 255, .92);
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: clamp(1rem, 4vw, 2.5rem);
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      color: var(--ink);
      background:
        radial-gradient(1100px 620px at 12% -10%, rgba(255,255,255,.55), transparent 60%),
        radial-gradient(900px 520px at 105% 110%, rgba(255,255,255,.35), transparent 55%),
        linear-gradient(135deg, var(--brand-dark), var(--brand) 58%, var(--accent));
      background-attachment: fixed;
    }
    .mnt-shell { width: 100%; max-width: 720px; }
    .mnt-card {
      position: relative;
      overflow: hidden;
      background: var(--card);
      backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, .65);
      border-radius: 26px;
      box-shadow: 0 32px 70px rgba(3, 20, 8, .32);
      padding: clamp(1.6rem, 5vw, 3rem);
      text-align: center;
      animation: mnt-rise .6s cubic-bezier(.2, .8, .3, 1) both;
    }
    .mnt-card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 6px;
      background: linear-gradient(90deg, var(--brand), var(--accent), var(--brand-dark));
      background-size: 200% 100%;
      animation: mnt-slide 6s linear infinite;
    }
    @keyframes mnt-rise { from { opacity: 0; transform: translateY(18px) scale(.99); } to { opacity: 1; transform: none; } }
    @keyframes mnt-slide { from { background-position: 0 0; } to { background-position: 200% 0; } }
    .mnt-brand { display: flex; align-items: center; justify-content: center; gap: .7rem; margin-bottom: 1.4rem; }
    .mnt-logo { max-height: 46px; width: auto; object-fit: contain; }
    .mnt-mark {
      display: inline-grid; place-items: center;
      width: 52px; height: 52px; border-radius: 16px;
      background: linear-gradient(140deg, var(--brand), var(--brand-dark));
      color: #fff; font-weight: 800; letter-spacing: .04em;
      box-shadow: 0 10px 24px rgba(12, 128, 15, .35);
    }
    .mnt-icon {
      position: relative;
      width: 96px; height: 96px; margin: 0 auto 1.3rem;
      display: grid; place-items: center;
      border-radius: 50%;
      background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.9), rgba(255,255,255,.35));
      color: var(--brand-dark);
      box-shadow: inset 0 0 0 1px rgba(12, 128, 15, .18), 0 16px 34px rgba(3, 20, 8, .14);
    }
    .mnt-icon::after {
      content: "";
      position: absolute; inset: -10px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, .75);
      animation: mnt-pulse 2.6s ease-out infinite;
    }
    @keyframes mnt-pulse {
      0% { transform: scale(.86); opacity: .85; }
      70% { transform: scale(1.12); opacity: 0; }
      100% { transform: scale(1.12); opacity: 0; }
    }
    .mnt-icon svg { width: 46px; height: 46px; animation: mnt-spin 9s linear infinite; }
    @keyframes mnt-spin { to { transform: rotate(360deg); } }
    h1 { margin: 0 0 .55rem; font-size: clamp(1.5rem, 4.4vw, 2.1rem); letter-spacing: -.02em; }
    .mnt-lead { margin: 0 auto; max-width: 46ch; color: var(--muted); font-size: clamp(.95rem, 2.4vw, 1.05rem); line-height: 1.6; }
    .mnt-note {
      display: inline-flex; align-items: center; gap: .5rem;
      margin-top: 1.2rem; padding: .45rem .9rem;
      border-radius: 999px; font-size: .78rem; font-weight: 600;
      color: var(--brand-dark); background: rgba(12, 128, 15, .1);
    }
    .mnt-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 0 0 rgba(12,128,15,.5); animation: mnt-blink 1.8s ease-in-out infinite; }
    @keyframes mnt-blink { 50% { opacity: .35; } }
    .mnt-contact {
      display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center;
      margin: 1.7rem 0 .2rem; padding: 0; list-style: none;
    }
    .mnt-contact a, .mnt-contact span {
      display: inline-flex; align-items: center; gap: .45rem;
      padding: .6rem .95rem; border-radius: 12px;
      font-size: .85rem; font-weight: 600; text-decoration: none;
      color: var(--ink); background: rgba(15, 23, 42, .06);
      border: 1px solid rgba(15, 23, 42, .08);
      transition: transform .15s ease, background .15s ease;
    }
    .mnt-contact a:hover { background: rgba(12, 128, 15, .12); transform: translateY(-1px); }
    .mnt-contact i { font-style: normal; opacity: .75; }
    .mnt-address { margin: 1.3rem 0 0; font-size: .8rem; color: var(--muted); line-height: 1.5; }
    .mnt-foot { margin: 1.5rem 0 0; font-size: .75rem; color: rgba(255, 255, 255, .85); text-align: center; }
    .mnt-foot a { color: #fff; text-underline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) {
      .mnt-card, .mnt-card::before, .mnt-icon::after, .mnt-icon svg, .mnt-dot { animation: none !important; }
    }
  </style>
</head>
<body>
  <main class="mnt-shell">
    <div class="mnt-card">
      <div class="mnt-brand"><?= $logoHtml ?></div>

      <div class="mnt-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3.2"></circle>
          <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.55-1.1 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9.1a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87v.05a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"></path>
        </svg>
      </div>

      <h1>We&rsquo;re making things better</h1>
      <?php
      $lead = $storeName . ' is offline for a short maintenance window.';
      if ($tagline !== '') {
          $lead = $storeName . ' — ' . $tagline . ' — is offline for a short maintenance window.';
      }
      ?>
      <p class="mnt-lead">
        <?= e($lead) ?>
        We are restocking shelves, checking prices and polishing the checkout.
        Your cart, wishlist and past orders are safe, and everything will be back very soon.
      </p>

      <span class="mnt-note"><span class="mnt-dot" aria-hidden="true"></span> Back online shortly — thank you for your patience</span>

      <?php if ($email !== '' || $phone !== '' || $whatsapp !== ''): ?>
        <ul class="mnt-contact">
          <?php if ($phone !== ''): ?>
            <li><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone) ?? $phone) ?>"><i aria-hidden="true">&#9742;</i> <?= e($phone) ?></a></li>
          <?php endif; ?>
          <?php if ($whatsapp !== ''): ?>
            <li><a href="https://wa.me/<?= e(ltrim($whatsapp, '+')) ?>" rel="noopener"><i aria-hidden="true">&#128172;</i> WhatsApp</a></li>
          <?php endif; ?>
          <?php if ($email !== ''): ?>
            <li><a href="mailto:<?= e($email) ?>"><i aria-hidden="true">&#9993;</i> <?= e($email) ?></a></li>
          <?php endif; ?>
        </ul>
      <?php endif; ?>

      <?php if ($address !== ''): ?>
        <p class="mnt-address"><?= e($address) ?></p>
      <?php endif; ?>
    </div>

    <p class="mnt-foot">
      Store team? <a href="/login.php?next=%2Fadmin%2Fsettings.php%3Ftab%3Dcommerce">Sign in to turn the store back on</a>
    </p>
  </main>
</body>
</html>
    <?php
    exit;
}
